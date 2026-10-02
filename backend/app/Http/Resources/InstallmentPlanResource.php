<?php

namespace App\Http\Resources;

use App\Domain\Cards\Models\InstallmentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o plano vindo de App\Domain\Cards\Queries\InstallmentPlanList
 * (contagens, restante e próxima data carregados).
 *
 * @mixin InstallmentPlan
 */
final class InstallmentPlanResource extends JsonResource
{
    /**
     * @return array{id: int, account_id: int, description: string, total_amount: int, installments: int, installment_amount: int, purchase_date: string, cancelled_at: string|null, category_id: int|null, posted_count: int, projected_count: int, remaining_amount: int, next_date: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'description' => $this->description,
            'total_amount' => $this->total_amount->cents,
            'installments' => $this->installments,
            'installment_amount' => intdiv($this->total_amount->cents, max($this->installments, 1)),
            'purchase_date' => $this->purchase_date->toDateString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'category_id' => $this->category_id === null ? null : (int) $this->category_id,
            'posted_count' => (int) $this->posted_count,
            'projected_count' => (int) $this->projected_count,
            'remaining_amount' => (int) ($this->remaining_amount ?? 0),
            'next_date' => $this->next_date === null ? null : substr((string) $this->next_date, 0, 10),
        ];
    }
}
