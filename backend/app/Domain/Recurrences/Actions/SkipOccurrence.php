<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Recurrences\Errors\OccurrenceNotProjected;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;

/**
 * Pula uma ocorrência prevista ("não aconteceu"): exclui a transação. Como
 * GenerateOccurrences nunca reconsidera uma data <= generated_until, ela
 * nunca volta.
 */
final class SkipOccurrence
{
    /**
     * @throws OccurrenceNotProjected
     */
    public function handle(Transaction $transaction): void
    {
        if ($transaction->status !== TransactionStatus::Projected || $transaction->recurrence_id === null) {
            throw new OccurrenceNotProjected;
        }

        $transaction->delete();
    }
}
