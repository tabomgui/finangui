<?php

namespace App\Domain\Reports\Data;

/**
 * Uma linha de App\Domain\Reports\Data\SpendingBreakdownResult: despesa de
 * uma categoria-raiz (ou "Sem categoria", com categoryId nulo) no período
 * pedido, com o detalhamento por subcategoria em $children (vazio quando
 * nenhuma subcategoria tem gasto — ver
 * App\Domain\Reports\Queries\SpendingBreakdown).
 */
final readonly class SpendingCategoryItem
{
    /**
     * @param  list<SpendingChildItem>  $children
     */
    public function __construct(
        public ?int $categoryId,
        public string $name,
        public ?string $color,
        public ?string $icon,
        public int $amount,
        public int $count,
        public array $children,
    ) {}
}
