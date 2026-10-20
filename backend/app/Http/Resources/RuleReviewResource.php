<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RuleReviewGroupResult;
use App\Domain\Rules\Data\RuleReviewResult;
use App\Domain\Rules\Enums\ReviewGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
     * items: cada item no formato de CategoryChangeItemResource.
     *
     * @return array{
     *     max_changes: int,
     *     groups: list<array{key: ReviewGroup, count: int, applicable: bool, truncated: bool, items: AnonymousResourceCollection}>,
     * }
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
