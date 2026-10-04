<?php

namespace App\Http\Resources;

use App\Domain\Reports\Data\MonthlyEvolutionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de
 * App\Domain\Reports\Queries\MonthlyEvolution::for().
 *
 * @mixin MonthlyEvolutionResult
 */
final class MonthlyEvolutionResource extends JsonResource
{
    /**
     * @return array{currency: string, months: list<array{month: string, income: int, expense: int, net: int}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'months' => $this->months,
        ];
    }
}
