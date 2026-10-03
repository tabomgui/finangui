<?php

namespace App\Domain\Imports\Queries;

use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * Ids dos lotes revertíveis do usuário autenticado: só o lote completed
 * mais recente de cada conta (ver RevertImportBatch) — uma única consulta
 * agrupada por conta, para o índice não perguntar "é o mais recente?"
 * lote a lote.
 */
final class RevertibleBatches
{
    /**
     * @return list<int>
     */
    public function ids(): array
    {
        $ranked = ImportBatch::query()
            ->select(['id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY account_id ORDER BY completed_at DESC, id DESC) AS rn')
            ->where('status', ImportBatchStatus::Completed->value)
            // format pluggy nunca é revertível (ver RevertImportBatch).
            ->where('format', '!=', ImportFormat::Pluggy->value);

        return DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
