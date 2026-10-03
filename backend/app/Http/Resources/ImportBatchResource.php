<?php

namespace App\Http\Resources;

use App\Domain\Imports\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportBatch
 */
final class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'format' => $this->format,
            'format_label' => $this->format->label(),
            'filename' => $this->filename,
            'status' => $this->status,
            // Enquanto pendente só tem `failed` (as outras contagens só
            // existem depois de confirmado); nunca null, sempre um objeto.
            'stats' => $this->stats ?? [],
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reverted_at' => $this->reverted_at?->toIso8601String(),
        ];
    }
}
