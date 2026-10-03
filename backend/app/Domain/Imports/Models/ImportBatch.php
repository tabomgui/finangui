<?php

namespace App\Domain\Imports\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ImportFormat $format
 * @property ImportBatchStatus $status
 * @property list<array<string, mixed>>|null $rows
 * @property array<string, mixed>|null $stats
 * @property list<array<string, mixed>>|null $undo
 * @property list<int>|null $created_statement_ids
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $reverted_at
 * @property bool|null $revertible atributo dinâmico (não é coluna), pré-calculado por ImportBatchController::index() via RevertibleBatches; ImportBatchResource recalcula quando ausente
 */
class ImportBatch extends Model
{
    use BelongsToUser;

    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'format', 'source', 'filename', 'status',
        'rows', 'stats', 'undo', 'created_statement_ids', 'completed_at', 'reverted_at',
    ];

    /**
     * Espelha o default da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ImportFormat::class,
            'status' => ImportBatchStatus::class,
            'rows' => 'array',
            'stats' => 'array',
            'undo' => 'array',
            'created_statement_ids' => 'array',
            'completed_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ImportBatchFactory
    {
        return ImportBatchFactory::new();
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
        return $this->hasMany(Transaction::class, 'import_batch_id');
    }
}
