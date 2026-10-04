<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;

/**
 * Cancela um lote pendente: um `delete()` normal primeiro leria a linha e só
 * então apagaria, então um ingest concorrente (confirmar) que termine entre
 * a leitura e o delete faria o cancelamento apagar um lote que já não está
 * mais pendente. O `delete` atômico com `status = pending` na própria
 * cláusula fecha essa janela — se zero linhas foram afetadas, o lote não
 * estava mais pendente (já confirmado ou revertido, ou cancelado por outra
 * requisição concorrente).
 */
final class CancelImportBatch
{
    /**
     * @throws ImportBatchNotPending
     */
    public function handle(ImportBatch $batch): void
    {
        $deleted = ImportBatch::query()
            ->whereKey($batch->id)
            ->where('status', ImportBatchStatus::Pending)
            // format pluggy nunca fica pending de verdade (ver ConfirmImportBatch).
            ->where('format', '!=', ImportFormat::Pluggy->value)
            ->delete();

        if ($deleted === 0) {
            throw new ImportBatchNotPending;
        }
    }
}
