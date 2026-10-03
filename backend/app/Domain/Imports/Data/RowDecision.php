<?php

namespace App\Domain\Imports\Data;

use App\Domain\Imports\Enums\RowOutcome;

/**
 * Destino de uma linha parseada, decidido pelo IngestionPlanner sem gravar
 * nada: usado tanto pela prévia quanto pela confirmação, para garantir que
 * as duas vejam os mesmos números.
 */
final readonly class RowDecision
{
    public function __construct(
        public ParsedRow $row,
        public RowOutcome $outcome,
        public ?int $transactionId = null,
    ) {}
}
