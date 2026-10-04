<?php

namespace App\Domain\Budgets\Data;

/**
 * Resultado de App\Domain\Budgets\Queries\MonthBudget::for().
 */
final readonly class MonthBudgetResult
{
    /**
     * @param  list<array{category: array{id: int, name: string, icon: string|null, color: string|null}, amount: int, source: 'default'|'override', spent: int, remaining: int, percent: int}>  $items
     * @param  array{budgeted: int, spent: int}  $totals
     */
    public function __construct(
        public string $month,
        public string $currency,
        public array $items,
        public array $totals,
        public int $unbudgetedSpent,
    ) {}
}
