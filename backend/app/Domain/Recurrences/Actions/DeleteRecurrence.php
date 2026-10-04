<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Cards\Models\CardStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Exclui as previstas da recorrência (qualquer data, não só futuras); as já
 * lançadas ficam, com recurrence_id e recurrence_date explicitamente
 * zerados antes do modelo ser excluído (o FK nullOnDelete por si só zeraria
 * só recurrence_id).
 */
final class DeleteRecurrence
{
    public function handle(Recurrence $recurrence): void
    {
        DB::transaction(function () use ($recurrence) {
            Transaction::query()
                ->where('recurrence_id', $recurrence->id)
                ->where('status', TransactionStatus::Projected->value)
                ->delete();

            Transaction::query()
                ->where('recurrence_id', $recurrence->id)
                ->update(['recurrence_id' => null, 'recurrence_date' => null]);

            $accountId = $recurrence->account_id;

            $recurrence->delete();

            CardStatement::pruneEmptyFuture($accountId);
        });
    }
}
