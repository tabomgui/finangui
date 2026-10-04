<?php

namespace App\Http\Resources;

use App\Domain\Goals\Models\GoalContribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoalContribution
 */
final class GoalContributionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount->cents,
            'date' => $this->date->toDateString(),
            'note' => $this->note,
        ];
    }
}
