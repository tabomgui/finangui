<?php

namespace App\Domain\Reports\Data;

/**
 * Uma linha de App\Domain\Reports\Data\CategoryComparisonResult: despesa de
 * uma categoria-raiz (ou "Sem categoria", com categoryId nulo) nos dois
 * períodos comparados. Tipo próprio em vez de um array (ver
 * App\Domain\Banking\Data\ProviderAccountSuggestion para o porquê): o
 * resource (App\Http\Resources\CategoryComparisonResource) só documenta o
 * tipo real de cada campo quando lê de propriedades tipadas, nunca de chaves
 * de um array genérico.
 */
final readonly class CategoryComparisonItem
{
    public function __construct(
        public ?int $categoryId,
        public string $name,
        public ?string $icon,
        public ?string $color,
        public int $a,
        public int $b,
        public int $delta,
    ) {}
}
