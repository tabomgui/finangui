<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property AccountType $type
 * @property Money $opening_balance
 */
class Account extends Model
{
    use BelongsToUser;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'type', 'currency', 'opening_balance', 'color', 'icon', 'is_archived'];

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
        ];
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
        $query->select('accounts.*')->addSelect(['balance_net' => Transaction::query()
            ->withoutGlobalScopes()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
            ->whereColumn('transactions.account_id', 'accounts.id')
            ->where('status', TransactionStatus::Posted->value)
            ->where('is_ignored', false)
            ->when($asOf, fn (Builder $q) => $q->whereDate('date', '<=', $asOf->toDateString())),
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
}
