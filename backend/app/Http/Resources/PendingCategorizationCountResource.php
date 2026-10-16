<?php

namespace App\Http\Resources;

use App\Domain\Categorization\Data\PendingCategorizationCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de
 * App\Domain\Categorization\Queries\PendingCategorization::count().
 *
 * @mixin PendingCategorizationCount
 */
final class PendingCategorizationCountResource extends JsonResource
{
    /**
     * @return array{total_pending: int, group_count: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'total_pending' => $this->totalPending,
            'group_count' => $this->groupCount,
        ];
    }
}
