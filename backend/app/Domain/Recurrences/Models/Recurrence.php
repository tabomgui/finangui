<?php

namespace App\Domain\Recurrences\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\RecurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo de um lançamento que se repete (aluguel, salário, assinatura).
 * GenerateOccurrences cria as transações previstas a partir dele; editar os
 * campos simples do modelo propaga para as previstas futuras (fora do escopo
 * desta tarefa).
 *
 * @property Money $amount
 * @property Direction $direction
 * @property Frequency $frequency
 * @property int $interval
 * @property int|null $day_of_month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $generated_until
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
}
