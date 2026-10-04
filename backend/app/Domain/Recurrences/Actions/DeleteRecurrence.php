<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Cards\Models\CardStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Exclui as ocorrências ainda não confirmadas da recorrência (qualquer
 * data, não só futuras — Transaction::isUnconfirmedOccurrence()); as que já
 * são (ou já foram) um lançamento de verdade — lançadas à mão, ou uma
 * prevista adotada por importação/banco que ainda carrega status projected
 * por ser datada no futuro — ficam, com recurrence_id e recurrence_date
 * explicitamente zerados antes do modelo ser excluído (o FK nullOnDelete
 * por si só zeraria só recurrence_id).
 */
final class DeleteRecurrence
{
    public function handle(Recurrence $recurrence): void
    {
        DB::transaction(function () use ($recurrence) {
            Transaction::query()
                ->where('recurrence_id', $recurrence->id)
                ->unconfirmedOccurrences()
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
