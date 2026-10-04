<?php

namespace App\Domain\Reports\Data;

/**
 * Resultado de App\Domain\Reports\Queries\CategoryComparison::for(): despesa
 * por categoria-raiz nos dois períodos comparados, na moeda principal.
 * delta_percent é calculado no resource (App\Http\Resources
 * \CategoryComparisonResource), omitido quando a === 0 — por isso não entra
 * aqui, só a, b e delta.
 */
final readonly class CategoryComparisonResult
{
    /**
     * @param  list<CategoryComparisonItem>  $items
     * @param  array{a: int, b: int}  $totals
     */
    public function __construct(
        public string $currency,
        public array $items,
        public array $totals,
    ) {}
}
