<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RuleReconcileApplyResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RuleReconcileApplyResult
 */
final class RuleReconcileApplyResource extends JsonResource
{
    /**
     * @return array{recategorize: int, fill: int, clear: int, skipped: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'recategorize' => $this->recategorize,
            'fill' => $this->fill,
            'clear' => $this->clear,
            'skipped' => $this->skipped,
        ];
    }
}
