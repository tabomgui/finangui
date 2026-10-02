<?php

namespace App\Domain\Transactions\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
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
        'is_ignored', 'transfer_id', 'raw',
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
        ];
    }

    protected static function newFactory(): TransactionFactory
    {
        return TransactionFactory::new();
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

    public function isTransferLeg(): bool
    {
        return $this->transfer_id !== null;
    }
}
