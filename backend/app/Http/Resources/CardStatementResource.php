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
     * @return array{id: int, account_id: int, closing_date: string, due_date: string, reported_total: int|null, total: int, computed_total: int, paid: int, remaining: int, status: StatementStatus, days_until_due: int, is_overdue: bool, has_divergence: bool, history_incomplete?: true, history_incomplete_since?: string}
     */
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $computedTotal = $this->resource->computedTotal()->cents;
        $total = $this->resource->total($today)->cents;
        $status = $this->resource->status($today);
        $closed = $this->resource->isClosed($today);
        $historyIncompleteSince = $this->resource->historyIncompleteSince();

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'closing_date' => $this->closing_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'reported_total' => $this->reported_total?->cents,
            'total' => $total,
            'computed_total' => $computedTotal,
            'paid' => $this->resource->paid()->cents,
            'remaining' => $this->resource->remaining($today)->cents,
            'status' => $status,
            'days_until_due' => (int) $today->diffInDays($this->due_date, false),
            'is_overdue' => (bool) (in_array($status, [StatementStatus::Closed, StatementStatus::Partial], true) && $this->due_date->lessThan($today)),
            // Nunca é divergência quando os lançamentos desta fatura são de
            // antes do histórico compartilhado pelo banco (ver
            // CardStatement::historyIncompleteSince()): o calculado é 0 (ou
            // bem menor) só por falta de lançamento local, não porque o
            // banco e o app discordam de verdade.
            'has_divergence' => (bool) ($closed && $historyIncompleteSince === null && $this->reported_total !== null && $this->reported_total->cents !== $computedTotal),
            'history_incomplete' => $this->when($historyIncompleteSince !== null, true),
            'history_incomplete_since' => $this->when($historyIncompleteSince !== null, fn (): string => $historyIncompleteSince->toDateString()),
        ];
    }
}
