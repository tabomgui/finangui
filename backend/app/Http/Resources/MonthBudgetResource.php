<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o array já pronto de App\Domain\Budgets\Queries\MonthBudget::for().
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
        /** @var array{month: string, currency: string, items: list<array{category: array{id: int, name: string, icon: string|null, color: string|null}, amount: int, source: 'default'|'override', spent: int, remaining: int, percent: int}>, totals: array{budgeted: int, spent: int}, unbudgeted_spent: int} $data */
        $data = $this->resource;

        return $data;
    }
}
