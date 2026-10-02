<?php

namespace App\Domain\Cards\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Parcelamentos com o progresso (lançadas/projetadas, restante, próxima data)
 * e a categoria atual (a da última parcela) num só select, sem N+1.
 */
final class InstallmentPlanList
{
    /**
     * @return Collection<int, InstallmentPlan>
     */
    public function forCard(Account $card): Collection
    {
        return $this->baseQuery()->where('account_id', $card->id)->get();
    }

    public function find(int $planId): InstallmentPlan
    {
        return $this->baseQuery()->whereKey($planId)->firstOrFail();
    }

    /**
     * @return Builder<InstallmentPlan>
     */
    private function baseQuery(): Builder
    {
        $projected = fn ($q) => $q->where('status', TransactionStatus::Projected->value);

        return InstallmentPlan::query()
            ->withCount([
                'transactions as posted_count' => fn ($q) => $q->where('status', '!=', TransactionStatus::Projected->value),
                'transactions as projected_count' => $projected,
            ])
            ->withSum(['transactions as remaining_amount' => $projected], 'amount')
            ->withMin(['transactions as next_date' => $projected], 'date')
            ->addSelect(['category_id' => Transaction::query()->withoutGlobalScopes()
                ->select('category_id')
                ->whereColumn('installment_plan_id', 'installment_plans.id')
                ->orderByDesc('installment_number')
                ->limit(1)])
            ->orderByRaw('cancelled_at IS NOT NULL')
            ->orderByDesc('purchase_date');
    }
}
