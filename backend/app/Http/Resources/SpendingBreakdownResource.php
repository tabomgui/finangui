<?php

namespace App\Http\Resources;

use App\Domain\Reports\Data\SpendingBreakdownResult;
use App\Domain\Reports\Data\SpendingCategoryItem;
use App\Domain\Reports\Data\SpendingChildItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de
 * App\Domain\Reports\Queries\SpendingBreakdown::for().
 *
 * @mixin SpendingBreakdownResult
 */
final class SpendingBreakdownResource extends JsonResource
{
    /**
     * @return array{currency: string, total: int, categories: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        $categories = [];
        foreach ($this->categories as $category) {
            $categories[] = $this->categoryToArray($category);
        }

        return [
            'currency' => $this->currency,
            'total' => $this->total,
            'categories' => $categories,
        ];
    }

    /**
     * category_id some no grupo "Sem categoria" (categoryId nulo) — ver
     * App\Domain\Reports\Data\SpendingCategoryItem.
     *
     * @return array<string, mixed>
     */
    private function categoryToArray(SpendingCategoryItem $category): array
    {
        $children = [];
        foreach ($category->children as $child) {
            $children[] = $this->childToArray($child);
        }

        return [
            'category_id' => $this->when($category->categoryId !== null, fn () => (int) $category->categoryId),
            'name' => $category->name,
            'color' => $category->color,
            'icon' => $category->icon,
            'amount' => $category->amount,
            'count' => $category->count,
            'children' => $children,
        ];
    }

    /**
     * direct só aparece (sempre true) na entrada que representa o gasto
     * lançado direto na categoria pai — ver
     * App\Domain\Reports\Data\SpendingChildItem.
     *
     * @return array<string, mixed>
     */
    private function childToArray(SpendingChildItem $child): array
    {
        return [
            'category_id' => $child->categoryId,
            'name' => $child->name,
            'color' => $child->color,
            'icon' => $child->icon,
            'amount' => $child->amount,
            'count' => $child->count,
            'direct' => $this->when($child->direct, fn () => true),
        ];
    }
}
