<?php

namespace App\Http\Resources;

use App\Domain\Reports\Data\CategoryComparisonItem;
use App\Domain\Reports\Data\CategoryComparisonResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de
 * App\Domain\Reports\Queries\CategoryComparison::for().
 *
 * @mixin CategoryComparisonResult
 */
final class CategoryComparisonResource extends JsonResource
{
    /**
     * @return array{currency: string, items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function toArray(Request $request): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[] = $this->itemToArray($item);
        }

        return [
            'currency' => $this->currency,
            'items' => $items,
            'totals' => $this->totalsToArray(),
        ];
    }

    /**
     * delta_percent some quando a === 0 (divisão sem sentido), mesma regra
     * de itemToArray().
     *
     * @return array<string, mixed>
     */
    private function totalsToArray(): array
    {
        $totals = $this->totals;

        return [
            'a' => $totals['a'],
            'b' => $totals['b'],
            'delta' => $totals['delta'],
            'delta_percent' => $this->when($totals['a'] !== 0, fn () => (int) round($totals['delta'] * 100 / $totals['a'])),
        ];
    }

    /**
     * delta_percent some quando a === 0 (divisão sem sentido) — ver
     * App\Domain\Reports\Data\CategoryComparisonResult.
     *
     * @return array<string, mixed>
     */
    private function itemToArray(CategoryComparisonItem $item): array
    {
        return [
            'category_id' => $item->categoryId,
            'name' => $item->name,
            'icon' => $item->icon,
            'color' => $item->color,
            'a' => $item->a,
            'b' => $item->b,
            'delta' => $item->delta,
            'delta_percent' => $this->when($item->a !== 0, fn () => (int) round($item->delta * 100 / $item->a)),
        ];
    }
}
