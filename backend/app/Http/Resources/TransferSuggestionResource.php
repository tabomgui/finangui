<?php

namespace App\Http\Resources;

use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferSuggestion
 */
final class TransferSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'out' => TransactionResource::make($this->outTransaction),
            'in' => TransactionResource::make($this->inTransaction),
        ];
    }
}
