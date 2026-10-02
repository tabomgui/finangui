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
            'kind' => $this->kind->value,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_transfer' => $this->is_transfer,
            'is_archived' => $this->is_archived,
        ];
    }
}
