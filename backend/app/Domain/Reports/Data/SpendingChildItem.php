<?php

namespace App\Domain\Reports\Data;

/**
 * Uma entrada de App\Domain\Reports\Data\SpendingCategoryItem::$children:
 * subcategoria com gasto, ou o gasto lançado direto na categoria pai
 * (categoryId = id do pai, direct = true — ver
 * App\Domain\Reports\Queries\SpendingBreakdown).
 */
final readonly class SpendingChildItem
{
    public function __construct(
        public int $categoryId,
        public string $name,
        public ?string $color,
        public ?string $icon,
        public int $amount,
        public int $count,
        public bool $direct,
    ) {}
}
