<?php

namespace App\Domain\Transfers\Models;

use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\TransferSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TransferSuggestionStatus $status
 * @property float $score
 */
class TransferSuggestion extends Model
{
    use BelongsToUser;

    /** @use HasFactory<TransferSuggestionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'out_transaction_id', 'in_transaction_id', 'score', 'status',
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
            'score' => 'float',
            'status' => TransferSuggestionStatus::class,
        ];
    }

    protected static function newFactory(): TransferSuggestionFactory
    {
        return TransferSuggestionFactory::new();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function outTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'out_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function inTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'in_transaction_id');
    }
}
