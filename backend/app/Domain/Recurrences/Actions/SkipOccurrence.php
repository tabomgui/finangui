<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Recurrences\Errors\OccurrenceNotProjected;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Pula uma ocorrência ainda não confirmada
 * (Transaction::isUnconfirmedOccurrence(), "não aconteceu"): grava a data em
 * recurrences.skipped_dates e exclui a transação. Gravar a data (e não só
 * excluir) garante que ela nunca volta mesmo depois de uma mudança de
 * calendário ou pausa/reativação reabrir generated_until para antes dela.
 * Relê a transação sob lockForUpdate dentro da transação: evita pular a
 * mesma ocorrência duas vezes em paralelo (ex.: duplo clique).
 */
final class SkipOccurrence
{
    /**
     * @throws OccurrenceNotProjected
     */
    public function handle(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isUnconfirmedOccurrence()) {
                throw new OccurrenceNotProjected;
            }

            $recurrence = Recurrence::query()->findOrFail($locked->recurrence_id);
            $date = $locked->recurrence_date->toDateString();

            if (! in_array($date, $recurrence->skipped_dates, true)) {
                $recurrence->skipped_dates = [...$recurrence->skipped_dates, $date];
                $recurrence->save();
            }

            $locked->delete();
        });
    }
}
