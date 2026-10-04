<?php

namespace App\Domain\Imports\Enums;

/**
 * Destino de uma linha decidido pela cascata de dedup (ver IngestionPlanner).
 */
enum RowOutcome: string
{
    case New = 'new';
    case Duplicate = 'duplicate';
    case Update = 'update';
    case ReplaceInstallment = 'replace_installment';
    case Adopt = 'adopt';
    case SwapPending = 'swap_pending';
}
