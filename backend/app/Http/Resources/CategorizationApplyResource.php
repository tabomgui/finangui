<?php

namespace App\Http\Resources;

use App\Domain\Categorization\Data\CategorizationApplyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CategorizationApplyResult
 */
final class CategorizationApplyResource extends JsonResource
{
    /**
     * @return array{
     *     categorized: int,
     *     rule_applied?: int,
     *     skipped: int,
     *     rule?: array{id: int, name: string},
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'categorized' => $this->categorized,
            'rule_applied' => $this->when($this->ruleApplied !== null, fn () => $this->ruleApplied),
            'skipped' => $this->skipped,
            'rule' => $this->when($this->ruleId !== null, fn () => [
                'id' => $this->ruleId,
                'name' => $this->ruleName,
            ]),
        ];
    }
}
