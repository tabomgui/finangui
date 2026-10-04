<?php

namespace App\Domain\Imports\Data;

use App\Domain\Imports\Models\ImportBatch;

/**
 * Resultado de ImportPreview::for(): o lote, a decisão (vazia quando o
 * lote não está pendente) de cada linha, a categoria sugerida por linha
 * (só para outcome "new") e os dados de exibição da transação que cada
 * decisão não-"new" aponta.
 */
final readonly class ImportPreviewData
{
    /**
     * @param  list<RowDecision>  $decisions
     * @param  array<int, int>  $suggestedCategoryIds  categoria sugerida por número de linha
     * @param  array<int, array{id: int, date: string, description: string, kind: 'manual'|'recurrence'}>  $matches  por id de transação
     */
    public function __construct(
        public ImportBatch $batch,
        public array $decisions = [],
        public array $suggestedCategoryIds = [],
        public array $matches = [],
    ) {}
}
