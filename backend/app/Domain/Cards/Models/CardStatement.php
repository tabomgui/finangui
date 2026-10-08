<?php

namespace App\Domain\Cards\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\CardStatementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Fatura de cartão. Só datas (reais e editáveis) e o total informado pelo
 * banco são gravados; total, pago, restante e status são sempre derivados das
 * transações ligadas (scopeWithTotals).
 *
 * @property CarbonImmutable $closing_date
 * @property CarbonImmutable $due_date
 * @property Money|null $reported_total
 * @property Money|null $reported_paid
 */
class CardStatement extends Model
{
    use BelongsToUser;

    /** @use HasFactory<CardStatementFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'closing_date', 'due_date', 'external_id', 'reported_total', 'reported_paid'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closing_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'reported_total' => MoneyCast::class,
            'reported_paid' => MoneyCast::class,
        ];
    }

    protected static function newFactory(): CardStatementFactory
    {
        return CardStatementFactory::new();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'statement_id');
    }

    /**
     * Carrega cobranças líquidas (saídas − estornos) e pagamentos (entradas
     * marcadas como pagamento reconhecido — card_payment_statement_id, ver
     * App\Domain\Cards\Actions\AssignStatement e App\Domain\Banking\Actions\ReconcileCardPayments)
     * numa única query. Considera todo status, inclusive parcelas
     * projetadas; ignora transações marcadas como ignoradas (ex.: pagamento
     * duplicado).
     *
     * Numa fatura ainda aberta (closing_date > hoje), uma ocorrência de
     * recorrência ainda não confirmada (Transaction::isUnconfirmedOccurrence())
     * entra no total como previsão do ciclo em andamento; numa fatura já
     * fechada, ela sai do total — é só um palpite que nunca devia ter ficado
     * numa fatura que já não aceita mais lançamento novo (RecurrenceMatcher a
     * teria confirmado, ou ConfirmOccurrence/SkipOccurrence a teria resolvido),
     * mas o filtro fica aqui por segurança, para o total de uma fatura fechada
     * nunca incluir algo que ainda pode ser editado ou pulado.
     *
     * @param  Builder<CardStatement>  $query
     */
    public function scopeWithTotals(Builder $query): void
    {
        $today = CarbonImmutable::today()->toDateString();

        $linked = fn () => Transaction::query()->withoutGlobalScopes()
            ->whereColumn('transactions.statement_id', 'card_statements.id')
            ->where('transactions.is_ignored', false);

        $charges = $linked()->where(function (Builder $q) use ($today) {
            $q->where('card_statements.closing_date', '>', $today)
                ->orWhereNot(fn (Builder $q2) => $q2->unconfirmedOccurrences());
        });

        $query->select('card_statements.*')->addSelect([
            'charges_net' => $charges->selectRaw(
                "COALESCE(SUM(CASE WHEN transactions.direction = 'out' THEN transactions.amount WHEN transactions.card_payment_statement_id IS NULL THEN -transactions.amount ELSE 0 END), 0)"
            ),
            'payments_sum' => $linked()
                ->where('transactions.direction', Direction::In->value)
                ->whereNotNull('transactions.card_payment_statement_id')
                ->selectRaw('COALESCE(SUM(transactions.amount), 0)'),
        ]);
    }

    /**
     * Como withTotals(), com previous_closing/first_synced_date/provider_sync_from/
     * has_other_source_charge já prontos para historyIncompleteSince() nunca
     * precisar de uma query extra — usada só por App\Domain\Cards\Queries\CardOverview
     * e por CardController::statements() (listas: o resultado sempre inclui
     * TODAS as faturas da conta). previous_closing usa LAG() (window
     * function): só é correto quando a partição account_id está completa na
     * consulta — NUNCA use esta scope para buscar uma fatura isolada por id
     * (CardStatementController::show()/update()/pay(), que continuam em
     * withTotals() puro); historyIncompleteSince() cai para uma query
     * própria sempre que estes atributos não estiverem carregados.
     *
     * @param  Builder<CardStatement>  $query
     */
    public function scopeWithHistoryContext(Builder $query): void
    {
        $query->withTotals()
            ->selectRaw('LAG(card_statements.closing_date) OVER (PARTITION BY card_statements.account_id ORDER BY card_statements.closing_date) AS previous_closing')
            ->addSelect([
                'first_synced_date' => Transaction::query()->withoutGlobalScopes()
                    ->whereColumn('transactions.account_id', 'card_statements.account_id')
                    ->where('transactions.source', TransactionSource::Pluggy->value)
                    ->selectRaw('MIN(transactions.date)'),
                'provider_sync_from' => Account::query()->withoutGlobalScopes()
                    ->whereColumn('accounts.id', 'card_statements.account_id')
                    ->selectRaw('accounts.provider_sync_from'),
                'has_other_source_charge' => Transaction::query()->withoutGlobalScopes()
                    ->whereColumn('transactions.statement_id', 'card_statements.id')
                    ->where('transactions.is_ignored', false)
                    ->where('transactions.direction', Direction::Out->value)
                    ->where('transactions.source', '!=', TransactionSource::Pluggy->value)
                    ->selectRaw('COUNT(*) > 0'),
            ]);
    }

    /**
     * Total calculado: saídas − estornos ligados à fatura, sem pagamentos
     * (ver scopeWithTotals). Sempre o total de uma fatura aberta — o banco só
     * informa reported_total depois de fechar.
     */
    public function computedTotal(): Money
    {
        return Money::cents((int) $this->loadedTotal('charges_net'));
    }

    /**
     * Total exibido: o do banco (reported_total) quando a fatura já fechou e
     * ele foi informado; senão, o calculado. remaining() e status() usam este
     * total, nunca o calculado diretamente — ver computedTotal() para o aviso
     * de divergência (CardStatementResource).
     *
     * Limitação conhecida: reported_total é o total que o banco cobrou nesta
     * fatura — se ela foi paga parcialmente, o saldo que sobrou normalmente
     * já aparece somado ao reported_total da fatura seguinte (prática comum
     * de juros rotativo), não como uma dedução aqui. remaining() desta
     * fatura mostra só o que falta pagar dela mesma; o saldo residual que o
     * banco rolou para a próxima fatura só se reflete no reported_total dela.
     */
    public function total(?CarbonImmutable $today = null): Money
    {
        $today ??= CarbonImmutable::today();

        if ($this->reported_total !== null && $this->isClosed($today)) {
            return $this->reported_total;
        }

        return $this->computedTotal();
    }

    /**
     * Pago: o maior entre os pagamentos locais (payments_sum, ver
     * scopeWithTotals) e reported_paid (soma de payments[] da fatura do
     * banco, gravada por App\Domain\Banking\Actions\SyncBills) — nunca a
     * soma dos dois, que dobraria um pagamento que já é tanto local quanto
     * relatado pelo banco. reported_paid cobre uma fatura de antes do
     * histórico sincronizado (sem lançamento local nenhum: o Open Finance
     * só compartilha ~12 meses) que o banco mesmo assim diz que foi paga —
     * sem isso ela ficaria para sempre com paid=0, mesmo já quitada.
     * Overpagamento (reported_paid ou o local > total) não é erro: só
     * remaining() clampa em zero.
     */
    public function paid(): Money
    {
        $reportedPaidCents = $this->reported_paid !== null ? $this->reported_paid->cents : 0;

        return Money::cents(max((int) $this->loadedTotal('payments_sum'), $reportedPaidCents));
    }

    public function remaining(?CarbonImmutable $today = null): Money
    {
        return Money::cents(max($this->total($today)->cents - $this->paid()->cents, 0));
    }

    /**
     * Data a partir da qual o histórico de lançamentos desta conta é
     * confiável — null quando esta fatura não é "de antes do histórico
     * sincronizado" (ver App\Http\Resources\CardStatementResource, que usa
     * isto para omitir o aviso de divergência e expor history_incomplete).
     *
     * Referência usada: a MAIS TARDIA entre provider_sync_from (Account — a
     * partir de quando o sync bancário pode importar desta conta; null
     * numa conta criada direto pelo vínculo, sem piso) e a transação
     * source=pluggy mais antiga da conta (null sem nenhuma — inclusive
     * cartão manual, nunca sincronizado: a fatura nunca é "incompleta"). A
     * mais tardia, nunca ?? (que preferiria sempre provider_sync_from
     * quando presente): provider_sync_from é só o piso que a importação
     * RESPEITA, não uma garantia de que o banco de fato relatou algo desde
     * ali — a transação mais antiga de verdade (quando existe e é mais
     * recente que o piso) é a referência mais segura; sem nenhuma
     * transação ainda, o piso é o melhor que se tem. "De antes" significa:
     * o início do ciclo desta fatura (o fechamento da fatura anterior, ou,
     * sem fatura anterior, um mês antes deste fechamento) é anterior a
     * essa referência. Nunca incompleta quando a própria fatura já tem
     * alguma cobrança local de outra origem (CSV, OFX, manual,
     * parcelamento): o total calculado não depende só do que falta
     * sincronizar do banco.
     *
     * Lê previous_closing/first_synced_date/provider_sync_from/
     * has_other_source_charge quando já carregados (scopeWithHistoryContext,
     * usada por listas — ver a classe); sem isso, cada um cai para a
     * própria query (compatível com scopeWithTotals puro).
     */
    public function historyIncompleteSince(): ?CarbonImmutable
    {
        if ($this->hasOtherSourceCharge()) {
            return null;
        }

        $providerSyncFrom = $this->providerSyncFromDate();
        $firstSynced = $this->firstSyncedDate();

        $since = match (true) {
            $providerSyncFrom === null => $firstSynced,
            $firstSynced === null => $providerSyncFrom,
            $providerSyncFrom->greaterThan($firstSynced) => $providerSyncFrom,
            default => $firstSynced,
        };

        if ($since === null) {
            return null;
        }

        return $this->cycleStart()->lessThan($since) ? $since : null;
    }

    private function hasOtherSourceCharge(): bool
    {
        if (array_key_exists('has_other_source_charge', $this->attributes)) {
            return (bool) $this->attributes['has_other_source_charge'];
        }

        return Transaction::query()
            ->where('statement_id', $this->id)
            ->where('is_ignored', false)
            ->where('direction', Direction::Out->value)
            ->where('source', '!=', TransactionSource::Pluggy->value)
            ->exists();
    }

    private function firstSyncedDate(): ?CarbonImmutable
    {
        if (array_key_exists('first_synced_date', $this->attributes)) {
            $value = $this->attributes['first_synced_date'];

            return $value !== null ? CarbonImmutable::parse($value) : null;
        }

        $date = Transaction::query()
            ->where('account_id', $this->account_id)
            ->where('source', TransactionSource::Pluggy->value)
            ->min('date');

        return $date !== null ? CarbonImmutable::parse($date) : null;
    }

    private function providerSyncFromDate(): ?CarbonImmutable
    {
        if (array_key_exists('provider_sync_from', $this->attributes)) {
            $value = $this->attributes['provider_sync_from'];

            return $value !== null ? CarbonImmutable::parse($value) : null;
        }

        return Account::query()->find($this->account_id)?->provider_sync_from;
    }

    private function cycleStart(): CarbonImmutable
    {
        return $this->previousClosingDate() ?? $this->closing_date->subMonthNoOverflow();
    }

    private function previousClosingDate(): ?CarbonImmutable
    {
        if (array_key_exists('previous_closing', $this->attributes)) {
            $value = $this->attributes['previous_closing'];

            return $value !== null ? CarbonImmutable::parse($value) : null;
        }

        $value = static::query()
            ->where('account_id', $this->account_id)
            ->where('id', '!=', $this->id)
            ->where('closing_date', '<', $this->closing_date)
            ->orderByDesc('closing_date')
            ->value('closing_date');

        return $value !== null ? CarbonImmutable::parse($value) : null;
    }

    public function isClosed(CarbonImmutable $today): bool
    {
        return ! $today->startOfDay()->lessThan($this->closing_date);
    }

    public function status(CarbonImmutable $today): StatementStatus
    {
        if (! $this->isClosed($today)) {
            return StatementStatus::Open;
        }

        $total = $this->total($today)->cents;
        $paid = $this->paid()->cents;

        return match (true) {
            $total <= 0 || $paid >= $total => StatementStatus::Paid,
            $paid > 0 => StatementStatus::Partial,
            default => StatementStatus::Closed,
        };
    }

    /**
     * Exclui as faturas futuras (closing_date > hoje) do cartão que não têm
     * nenhum lançamento: refletiam um ciclo ou parcelamento que não existe
     * mais (dias do cartão mudaram, ou um parcelamento foi cancelado/excluído).
     * Faturas passadas, mesmo vazias, ficam — são histórico. Uma fatura com
     * total informado ou external_id (importada/editada à mão) também fica:
     * mesmo sem lançamento, carrega um dado que o usuário colocou de propósito.
     */
    public static function pruneEmptyFuture(int $accountId): void
    {
        static::query()
            ->where('account_id', $accountId)
            ->where('closing_date', '>', CarbonImmutable::today()->toDateString())
            ->whereNull('reported_total')
            ->whereNull('external_id')
            ->whereDoesntHave('transactions')
            ->delete();
    }

    private function loadedTotal(string $key): mixed
    {
        if (! array_key_exists($key, $this->attributes)) {
            throw new LogicException('Carregue a fatura com withTotals() antes de ler os totais.');
        }

        return $this->attributes[$key];
    }
}
