<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RuleReconcileGroupResult;
use App\Domain\Rules\Data\RuleReconcileResult;
use App\Domain\Rules\Enums\ReconcileClearReason;
use App\Domain\Rules\Enums\ReconcileGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Os três grupos sempre presentes, nesta ordem: recategorize, fill, clear.
 * "reason" só aparece no grupo clear.
 *
 * @mixin RuleReconcileResult
 */
final class RuleReconcileResource extends JsonResource
{
    /**
     * sample: cada item no formato de CategoryChangeItemResource.
     *
     * @return array{
     *     max_changes: int,
     *     groups: list<array{key: ReconcileGroup, count: int, reason?: ReconcileClearReason, sample: AnonymousResourceCollection}>,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'max_changes' => $this->maxChanges,
            'groups' => Collection::make($this->groups)->map(fn (RuleReconcileGroupResult $group) => [
                'key' => $group->key,
                'count' => $group->count,
                'reason' => $this->when($group->reason !== null, function () use ($group): ReconcileClearReason {
                    assert($group->reason !== null);

                    return $group->reason;
                }),
                'sample' => CategoryChangeItemResource::collection($group->sample),
            ])->all(),
        ];
    }
}
