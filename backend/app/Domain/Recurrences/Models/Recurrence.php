<?php

namespace App\Domain\Recurrences\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de um lançamento que se repete (aluguel, salário, assinatura).
 * GenerateOccurrences cria as transações previstas a partir dele; editar os
 * campos simples do modelo (App\Domain\Recurrences\Actions\UpdateRecurrence)
 * propaga para as previstas futuras.
 *
 * @property Money $amount
 * @property Direction $direction
 * @property Frequency $frequency
 * @property int $interval
 * @property int|null $day_of_month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $generated_until
 * @property list<string> $skipped_dates
 * @property-read string|null $next_date carregado por scopeWithNextDate()/loadMin()
 */
class Recurrence extends Model
{
    use BelongsToUser;

    /** @use HasFactory<RecurrenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'category_id', 'description', 'amount', 'direction',
        'frequency', 'interval', 'day_of_month', 'starts_on', 'ends_on',
        'generated_until', 'match_pattern', 'is_active',
    ];

    /**
     * Espelham os defaults da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'interval' => 1,
        'is_active' => true,
        'skipped_dates' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'direction' => Direction::class,
            'frequency' => Frequency::class,
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'generated_until' => 'immutable_date',
            'is_active' => 'boolean',
            'skipped_dates' => 'array',
        ];
    }

    protected static function newFactory(): RecurrenceFactory
    {
        return RecurrenceFactory::new();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('recurrence_date');
    }

    /**
     * Próxima ocorrência prevista (hoje em diante) num só select, sem N+1;
     * usada pela listagem.
     *
     * @param  Builder<Recurrence>  $query
     */
    public function scopeWithNextDate(Builder $query): void
    {
        $query->withMin(['transactions as next_date' => self::nextDateConstraint()], 'recurrence_date');
    }

    /**
     * Mesmo critério de scopeWithNextDate(), para um registro já carregado
     * (show/store/update, que lidam com um só modelo em vez de uma lista).
     */
    public function loadNextDate(): static
    {
        return $this->loadMin(['transactions as next_date' => self::nextDateConstraint()], 'recurrence_date');
    }

    /**
     * @return Closure(Builder<Transaction>): Builder<Transaction>
     */
    public static function nextDateConstraint(): Closure
    {
        $today = CarbonImmutable::today()->toDateString();

        return fn (Builder $query) => $query
            ->where('status', TransactionStatus::Projected->value)
            ->where('recurrence_date', '>=', $today);
    }
}
