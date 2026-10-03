<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Models\CardStatement;
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
        'provider_sync_from', 'provider_opening_set_at',
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
     * Carrega o saldo calculado numa única query (subselect por conta).
     * Saldo = opening_balance + Σ(posted, não ignoradas) com sinal por direction.
     * Com $asOf, considera só transações com date <= $asOf (comparação por data, sem hora).
     *
     * @param  Builder<Account>  $query
     */
    public function scopeWithBalance(Builder $query, ?CarbonInterface $asOf = null): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('accounts.*');
        }

        $query->addSelect(['balance_net' => Transaction::query()
            ->withoutGlobalScopes()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
            ->whereColumn('transactions.account_id', 'accounts.id')
            ->where('status', TransactionStatus::Posted->value)
            ->where('is_ignored', false)
            ->when($asOf, fn (Builder $q) => $q->where('date', '<=', $asOf->toDateString())),
        ]);
    }

    public function hasBalance(): bool
    {
        return array_key_exists('balance_net', $this->attributes);
    }

    public function balance(): Money
    {
        if (! $this->hasBalance()) {
            throw new LogicException('Carregue a conta com withBalance() antes de ler o saldo.');
        }

        return $this->opening_balance->plus(Money::cents((int) $this->attributes['balance_net']));
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
