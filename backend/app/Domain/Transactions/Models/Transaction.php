<?php

namespace App\Domain\Transactions\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property CarbonImmutable $date
 * @property Money $amount
 * @property Direction $direction
 * @property TransactionStatus $status
 * @property TransactionSource $source
 */
class Transaction extends Model
{
    use BelongsToUser;

    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'date', 'amount', 'direction', 'currency',
        'description', 'original_description', 'description_locked', 'notes',
        'category_id', 'payee', 'status', 'source', 'external_id', 'categorized_by',
        'is_ignored', 'transfer_id', 'raw', 'statement_id',
        'installment_plan_id', 'installment_number',
    ];

    /**
     * Espelham os defaults da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description_locked' => false,
        'status' => 'posted',
        'source' => 'manual',
        'is_ignored' => false,
        'description_key' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount' => MoneyCast::class,
            'direction' => Direction::class,
            'status' => TransactionStatus::class,
            'source' => TransactionSource::class,
            'description_locked' => 'boolean',
            'is_ignored' => 'boolean',
            'raw' => 'array',
            'installment_number' => 'integer',
        ];
    }

    protected static function newFactory(): TransactionFactory
    {
        return TransactionFactory::new();
    }

    protected static function booted(): void
    {
        // Mantida ao salvar: o histórico de categorização agrupa por ela.
        static::saving(function (Transaction $transaction) {
            if ($transaction->isDirty('description') || ! $transaction->exists) {
                $transaction->description_key = TextNormalizer::key($transaction->description);
            }
        });
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
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return BelongsTo<CardStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(CardStatement::class, 'statement_id');
    }

    /**
     * @return BelongsTo<InstallmentPlan, $this>
     */
    public function installmentPlan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class);
    }

    public function isTransferLeg(): bool
    {
        return $this->transfer_id !== null;
    }

    public function isInstallment(): bool
    {
        return $this->installment_plan_id !== null;
    }

    /**
     * Transações que contam como receita/despesa em relatórios: lançadas, não
     * ignoradas, fora de transferências e fora de categorias marcadas como
     * transferência — incluindo subcategorias cujo pai é marcado como
     * transferência (ex.: "Tesouro" dentro de "Investimentos").
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeReportable(Builder $query): void
    {
        $query->where('transactions.status', TransactionStatus::Posted->value)
            ->where('transactions.is_ignored', false)
            ->whereNull('transactions.transfer_id')
            ->where(fn (Builder $q) => $q->whereNull('transactions.category_id')
                ->orWhereHas('category', fn (Builder $c) => $c->where('is_transfer', false)
                    ->where(fn (Builder $c2) => $c2->whereNull('parent_id')
                        ->orWhereHas('parent', fn (Builder $p) => $p->where('is_transfer', false)))));
    }
}
