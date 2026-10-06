<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property AccountType $type
 * @property Money $opening_balance
 * @property Money|null $credit_limit
 * @property int|null $closing_day
 * @property int|null $due_day
 * @property string|null $last_four
 * @property int|null $connection_id
 * @property string|null $external_id
 * @property Money|null $provider_balance
 * @property CarbonImmutable|null $provider_synced_at
 * @property CarbonImmutable|null $provider_sync_from
 * @property CarbonImmutable|null $provider_opening_set_at
 * @property CarbonImmutable|null $provider_history_synced_at
 */
class Account extends Model
{
    use BelongsToUser;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'type', 'currency', 'opening_balance', 'color', 'icon', 'is_archived',
        'credit_limit', 'closing_day', 'due_day', 'last_four',
        'connection_id', 'external_id', 'provider_balance', 'provider_synced_at',
        'provider_sync_from', 'provider_opening_set_at', 'provider_history_synced_at',
    ];

    /**
     * Espelham os defaults da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'BRL',
        'opening_balance' => 0,
        'is_archived' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'opening_balance' => MoneyCast::class,
            'is_archived' => 'boolean',
            'credit_limit' => MoneyCast::class,
            'closing_day' => 'integer',
            'due_day' => 'integer',
            'provider_balance' => MoneyCast::class,
            'provider_synced_at' => 'immutable_datetime',
            'provider_sync_from' => 'immutable_date',
            'provider_opening_set_at' => 'immutable_datetime',
            'provider_history_synced_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    public function isCreditCard(): bool
    {
        return $this->type === AccountType::CreditCard;
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'connection_id');
    }

    /**
     * @return HasMany<CardStatement, $this>
     */
    public function statements(): HasMany
    {
        return $this->hasMany(CardStatement::class);
    }

    /**
     * Chaves selecionadas por scopeWithBalance(); conferidas por
     * hasBalance() antes de qualquer leitura em balance()/ledgerBalance(),
     * em vez de assumir que a presença de uma garante as outras.
     */
    private const BALANCE_KEYS = [
        'balance_net', 'balance_net_full', 'balance_net_today',
        'balance_bank_up_to_sync', 'balance_bank_up_to_asof',
    ];

    /**
     * Saldo real de cada conta no dia $asOf (hoje, no fuso do app, quando
     * omitido) — única regra do "saldo no dia" do domínio de Accounts,
     * usada aqui, em App\Domain\Reports\Queries\MonthSummary e em
     * GET /accounts.
     *
     * Cartão: sempre opening_balance + Σ(posted, não ignorada), sem corte
     * de data ($asOf é ignorado) — o mesmo razão completo de sempre, para
     * bater com o limite (scopeWithCardUsage) e a fatura.
     *
     * Conta conectada e que não é cartão (connection_id, provider_balance
     * e provider_synced_at todos não nulos): âncora no dia da última
     * sincronização — syncDate = provider_synced_at::date — e aplica a
     * diferença entre esse dia e $asOf:
     *     balance($asOf) = provider_balance − Σ(rows, date ≤ syncDate) + Σ(rows, date ≤ $asOf)
     * "rows" é posted e não ignorada; no lado do banco entra também a
     * transação ignorada que veio do próprio provedor (source pluggy ou
     * external_id preenchido) — o saldo do banco já contabilizou esse
     * lançamento mesmo ele estando ignorado aqui (ex.: um estorno
     * automático de investimento). pending/projected nunca entram, mesmo
     * que o saldo do banco os inclua — a API do provedor não distingue
     * isso de um lançamento efetivado.
     *
     * provider_synced_at é gravado (AccountMapper) e lido com o relógio do
     * próprio PHP, que roda no fuso do app (config('app.timezone'),
     * ver bootstrap) — por isso o cast direto para data (::date) já é o
     * dia local certo, sem precisar converter fuso dentro do SQL.
     *
     * Demais contas: opening_balance + Σ(posted, não ignorada, date ≤ $asOf).
     *
     * balance() decide qual soma usar; ledgerBalance() expõe sempre o
     * razão de hoje (independente de $asOf), para comparar com o saldo que
     * o banco informa (ver AccountResource). Os subselects correlacionados
     * abaixo saem numa única query (sem N+1).
     *
     * @param  Builder<Account>  $query
     */
    public function scopeWithBalance(Builder $query, ?CarbonInterface $asOf = null): void
    {
        $asOf ??= CarbonImmutable::now();
        $date = $asOf->toDateString();
        $today = CarbonImmutable::now()->toDateString();

        if ($query->getQuery()->columns === null) {
            $query->select('accounts.*');
        }

        $signed = "COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)";

        $posted = fn () => Transaction::query()
            ->withoutGlobalScopes()
            ->whereColumn('transactions.account_id', 'accounts.id')
            ->where('status', TransactionStatus::Posted->value);

        $ledgerRows = fn () => $posted()->where('is_ignored', false)->selectRaw($signed);

        // Lançamento do banco: não ignorado, ou ignorado mas que veio do
        // próprio provedor — já contabilizado no saldo que ele informa.
        $bankRows = fn () => $posted()
            ->where(fn (Builder $q) => $q->where('is_ignored', false)
                ->orWhere('source', TransactionSource::Pluggy->value)
                ->orWhereNotNull('external_id'))
            ->selectRaw($signed);

        $query->addSelect([
            'balance_net' => $ledgerRows()->where('date', '<=', $date),
            'balance_net_full' => $ledgerRows(),
            'balance_net_today' => $ledgerRows()->where('date', '<=', $today),
            'balance_bank_up_to_sync' => $bankRows()->whereRaw('transactions.date <= accounts.provider_synced_at::date'),
            'balance_bank_up_to_asof' => $bankRows()->where('date', '<=', $date),
        ]);
    }

    public function hasBalance(): bool
    {
        foreach (self::BALANCE_KEYS as $key) {
            if (! array_key_exists($key, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Conta conectada (não cartão) com saldo e data de sincronização do
     * banco: a regra de saldo bancário de scopeWithBalance() se aplica.
     */
    private function usesBankBalance(): bool
    {
        return ! $this->isCreditCard()
            && $this->connection_id !== null
            && $this->provider_balance !== null
            && $this->provider_synced_at !== null;
    }

    public function balance(): Money
    {
        if (! $this->hasBalance()) {
            throw new LogicException('Carregue a conta com withBalance() antes de ler o saldo.');
        }

        if ($this->isCreditCard()) {
            return $this->opening_balance->plus(Money::cents((int) $this->attributes['balance_net_full']));
        }

        if ($this->usesBankBalance()) {
            $delta = (int) $this->attributes['balance_bank_up_to_asof'] - (int) $this->attributes['balance_bank_up_to_sync'];

            return $this->provider_balance->plus(Money::cents($delta));
        }

        return $this->opening_balance->plus(Money::cents((int) $this->attributes['balance_net']));
    }

    /**
     * Saldo pelo razão interno (opening_balance + lançamentos postados não
     * ignorados) sempre até hoje, independente de $asOf — só para comparar
     * com o saldo que o banco informa (ver AccountResource), nunca para
     * decidir o saldo real da conta (balance()).
     */
    public function ledgerBalance(): Money
    {
        if (! $this->hasBalance()) {
            throw new LogicException('Carregue a conta com withBalance() antes de ler o saldo.');
        }

        return $this->opening_balance->plus(Money::cents((int) $this->attributes['balance_net_today']));
    }

    /**
     * Carrega o líquido do cartão para o cálculo de limite: o atual (lançadas e
     * pendentes) e o das parcelas projetadas. Recorrências projetadas não entram.
     *
     * @param  Builder<Account>  $query
     */
    public function scopeWithCardUsage(Builder $query): void
    {
        $signed = "COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)";
        $linked = fn () => Transaction::query()->withoutGlobalScopes()
            ->whereColumn('transactions.account_id', 'accounts.id')
            ->where('is_ignored', false);

        if ($query->getQuery()->columns === null) {
            $query->select('accounts.*');
        }

        $query->addSelect([
            'card_net_current' => $linked()->selectRaw($signed)
                ->whereIn('status', [TransactionStatus::Posted->value, TransactionStatus::Pending->value]),
            'card_net_projected' => $linked()->selectRaw($signed)
                ->where('status', TransactionStatus::Projected->value)
                ->whereNotNull('installment_plan_id'),
        ]);
    }

    /**
     * @return array{used: Money, projected: Money, available: Money}
     */
    public function cardUsage(): array
    {
        if (! array_key_exists('card_net_current', $this->attributes)) {
            throw new LogicException('Carregue o cartão com withCardUsage() antes de ler o limite.');
        }

        $used = max(-($this->opening_balance->cents + (int) $this->attributes['card_net_current']), 0);
        $projected = max(-(int) $this->attributes['card_net_projected'], 0);
        $limit = $this->credit_limit->cents ?? 0;

        return [
            'used' => Money::cents($used),
            'projected' => Money::cents($projected),
            'available' => Money::cents($limit - $used - $projected),
        ];
    }
}
