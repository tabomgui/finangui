<?php

namespace App\Domain\Reports\Data;

/**
 * Resultado de App\Domain\Reports\Queries\SpendingBreakdown::for(): despesa
 * por categoria-raiz no período pedido, na moeda principal, ordenada por
 * valor desc.
 */
final readonly class SpendingBreakdownResult
{
    /**
     * @param  list<SpendingCategoryItem>  $categories
     */
    public function __construct(
        public string $currency,
        public int $total,
        public array $categories,
    ) {}
}
