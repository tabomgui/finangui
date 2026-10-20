<?php

namespace App\Http\Resources;

use App\Domain\Categorization\Data\ProviderCategorizedCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProviderCategorizedCount
 */
final class ProviderCategorizedCountResource extends JsonResource
{
    /**
     * @return array{count: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'count' => $this->count,
        ];
    }
}
