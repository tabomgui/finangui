<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz um lote concluído: exclui o que ele inseriu (inclusive parcelas
 * projetadas e os planos que ficaram sem nenhuma transação), restaura as
 * transações que ele tocou (adotadas/substituídas/trocadas) ao estado
 * salvo no undo, e marca o lote como revertido. Duplicadas nunca mudaram,
 * então não precisam de restauração.
 */
final class RevertImportBatch
{
    /**
     * @throws ImportBatchNotRevertible
     */
    public function handle(ImportBatch $batch): void
    {
        if ($batch->status !== ImportBatchStatus::Completed) {
            throw new ImportBatchNotRevertible;
        }

        DB::transaction(function () use ($batch) {
            Transaction::query()->where('import_batch_id', $batch->id)->delete();

            InstallmentPlan::query()
                ->where('import_batch_id', $batch->id)
                ->whereDoesntHave('transactions')
                ->delete();

            /** @var list<array{transaction_id: int, attributes: array<string, mixed>}> $undo */
            $undo = $batch->undo ?? [];

            foreach ($undo as $item) {
                $transaction = Transaction::query()->find($item['transaction_id']);

                if ($transaction === null) {
                    // Já não existe (ex.: excluída manualmente depois da importação): nada a restaurar.
                    continue;
                }

                foreach ($item['attributes'] as $key => $value) {
                    $transaction->{$key} = $value;
                }

                $transaction->save();
            }

            // As parcelas projetadas excluídas podem ter deixado faturas futuras vazias.
            CardStatement::pruneEmptyFuture($batch->account_id);

            $batch->status = ImportBatchStatus::Reverted;
            $batch->reverted_at = CarbonImmutable::now();
            $batch->save();
        });
    }
}
