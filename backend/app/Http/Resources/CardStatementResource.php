<?php

namespace App\Http\Resources;

use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera a fatura carregada com withTotals().
 *
 * @mixin CardStatement
 */
final class CardStatementResource extends JsonResource
{
    /**
     * @return array{id: int, account_id: int, closing_date: string, due_date: string, reported_total: int|null, total: int, paid: int, remaining: int, status: StatementStatus, days_until_due: int, has_divergence: bool}
     */
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $total = $this->resource->total()->cents;

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'closing_date' => $this->closing_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'reported_total' => $this->reported_total?->cents,
            'total' => $total,
            'paid' => $this->resource->paid()->cents,
            'remaining' => $this->resource->remaining()->cents,
            'status' => $this->resource->status($today),
            'days_until_due' => (int) $today->diffInDays($this->due_date, false),
            'has_divergence' => (bool) ($this->reported_total !== null && $this->reported_total->cents !== $total),
        ];
    }
}
