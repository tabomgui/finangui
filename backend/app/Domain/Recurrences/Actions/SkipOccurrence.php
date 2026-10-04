<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Recurrences\Errors\OccurrenceNotProjected;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Pula uma ocorrência prevista ("não aconteceu"): grava a data em
 * recurrences.skipped_dates e exclui a transação. Gravar a data (e não só
 * excluir) garante que ela nunca volta mesmo depois de uma mudança de
 * calendário ou pausa/reativação reabrir generated_until para antes dela.
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

        DB::transaction(function () use ($transaction) {
            $recurrence = Recurrence::query()->findOrFail($transaction->recurrence_id);
            $date = $transaction->recurrence_date->toDateString();

            if (! in_array($date, $recurrence->skipped_dates, true)) {
                $recurrence->skipped_dates = [...$recurrence->skipped_dates, $date];
                $recurrence->save();
            }

            $transaction->delete();
        });
    }
}
