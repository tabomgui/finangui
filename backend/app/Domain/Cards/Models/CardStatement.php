<?php

namespace App\Domain\Cards\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Transactions\Enums\Direction;
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
 */
class CardStatement extends Model
{
    use BelongsToUser;

    /** @use HasFactory<CardStatementFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'closing_date', 'due_date', 'external_id', 'reported_total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closing_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'reported_total' => MoneyCast::class,
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

    public function paid(): Money
    {
        return Money::cents((int) $this->loadedTotal('payments_sum'));
    }

    public function remaining(?CarbonImmutable $today = null): Money
    {
        return Money::cents(max($this->total($today)->cents - $this->paid()->cents, 0));
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
