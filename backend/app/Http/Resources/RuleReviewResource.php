<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RuleReviewGroupResult;
use App\Domain\Rules\Data\RuleReviewResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Os quatro grupos sempre presentes, nesta ordem: fill, conflict, stale,
 * manual. "applicable" é falso só em manual (só informativo, nunca muda).
 *
 * @mixin RuleReviewResult
 */
final class RuleReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'max_changes' => $this->maxChanges,
            'groups' => Collection::make($this->groups)->map(fn (RuleReviewGroupResult $group) => [
                'key' => $group->key,
                'count' => $group->count,
                'applicable' => $group->key->applicable(),
                'truncated' => $group->truncated,
                'items' => CategoryChangeItemResource::collection($group->items),
            ])->all(),
        ];
    }
}
