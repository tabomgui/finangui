<?php

namespace App\Http\Resources;

use App\Domain\Rules\Models\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rule
 */
final class RuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'match' => $this->match,
            'conditions' => $this->conditions,
            'actions' => $this->actions,
            'last_applied_at' => $this->last_applied_at?->toIso8601String(),
            'last_applied_changes' => $this->last_applied_changes,
        ];
    }
}
