<?php

namespace App\Http\Resources;

use App\Domain\Categories\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_transfer' => $this->is_transfer,
            // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que parent_id/parent são nullable; em runtime uma categoria raiz não tem parent)
            'is_transfer_effective' => $this->is_transfer || ($this->parent?->is_transfer ?? false),
            'is_archived' => $this->is_archived,
        ];
    }
}
