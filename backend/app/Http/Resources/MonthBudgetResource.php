<?php

namespace App\Http\Resources;

use App\Domain\Budgets\Data\MonthBudgetResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de App\Domain\Budgets\Queries\MonthBudget::for().
 *
 * @mixin MonthBudgetResult
 */
final class MonthBudgetResource extends JsonResource
{
    /**
     * @return array{
     *     month: string,
     *     currency: string,
     *     items: list<array{category: array{id: int, name: string, icon: string|null, color: string|null}, amount: int, source: 'default'|'override', spent: int, remaining: int, percent: int}>,
     *     totals: array{budgeted: int, spent: int},
     *     unbudgeted_spent: int,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->month,
            'currency' => $this->currency,
            'items' => $this->items,
            'totals' => $this->totals,
            'unbudgeted_spent' => $this->unbudgetedSpent,
        ];
    }
}
