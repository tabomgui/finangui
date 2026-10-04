<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Exclui as previstas da recorrência (qualquer data, não só futuras); as já
 * lançadas ficam — o FK recurrence_id (nullOnDelete) zera recurrence_id e
 * recurrence_date delas quando o modelo é excluído a seguir.
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

            $recurrence->delete();
        });
    }
}
