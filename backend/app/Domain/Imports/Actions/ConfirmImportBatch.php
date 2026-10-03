<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;

/**
 * Prepara as linhas do lote pendente para IngestTransactions: recarrega o
 * lote (o chamador pode ter uma cópia defasada), descarta as linhas que o
 * usuário desmarcou na prévia e repassa o que o planner não decide (falhas
 * do parse, quantas foram puladas) para entrar no `stats` final.
 */
final class ConfirmImportBatch
{
    public function __construct(
        private readonly IngestTransactions $ingest,
    ) {}

    /**
     * @param  list<int>  $skipLines
     *
     * @throws ImportBatchNotPending
     */
    public function handle(ImportBatch $batch, array $skipLines): ImportBatch
    {
        $batch = $batch->refresh();

        // format pluggy nunca fica pending de verdade para alguém confirmar
        // (App\Domain\Banking\Actions\SyncTransactions ingere na hora, sem
        // passar por aqui) — checagem explícita, defesa a mais contra um
        // lote órfão de uma falha a meio caminho.
        if ($batch->status !== ImportBatchStatus::Pending || $batch->format === ImportFormat::Pluggy) {
            throw new ImportBatchNotPending;
        }

        $skip = array_fill_keys($skipLines, true);

        /** @var list<array<string, mixed>> $storedRows */
        $storedRows = $batch->rows ?? [];
        $rows = [];
        $skipped = 0;

        foreach ($storedRows as $data) {
            $row = ParsedRow::fromArray($data);

            if (isset($skip[$row->line])) {
                $skipped++;

                continue;
            }

            $rows[] = $row;
        }

        /** @var list<array{line: int, reason: string}> $failed */
        $failed = $batch->stats['failed'] ?? [];

        return $this->ingest->handle($batch, $rows, ['failed' => $failed, 'skipped' => $skipped]);
    }
}
