<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RuleReviewApplyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RuleReviewApplyResult
 */
final class RuleReviewApplyResource extends JsonResource
{
    /**
     * @return array{applied: int, skipped: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'applied' => $this->applied,
            'skipped' => $this->skipped,
        ];
    }
}
