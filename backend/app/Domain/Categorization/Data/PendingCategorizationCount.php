<?php

namespace App\Domain\Categorization\Data;

/**
 * Resultado de App\Domain\Categorization\Queries\PendingCategorization::count():
 * só os totais, para o aviso de pendentes na Início, sem carregar os
 * lançamentos inteiros.
 */
final readonly class PendingCategorizationCount
{
    public function __construct(
        public int $totalPending,
        public int $groupCount,
    ) {}
}
