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
        return $this->sort($this->baseQuery()->where('account_id', $card->id)->get());
    }

    public function find(int $planId): InstallmentPlan
    {
        return $this->baseQuery()->whereKey($planId)->firstOrFail();
    }

    /**
     * Cancelado por último; entre os ativos, o que ainda tem parcela
     * projetada vem antes do que já lançou todas (projected_count = 0);
     * dentro de cada grupo, o mais recente primeiro, id como critério final.
     * Em PHP (não em SQL): projected_count é uma coluna calculada por
     * subselect (alias), e o Postgres não resolve aliases do SELECT dentro
     * de uma expressão de ORDER BY (só quando o termo é o alias isolado).
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return Collection<int, InstallmentPlan>
     */
    private function sort(Collection $plans): Collection
    {
        return $plans->sort(fn (InstallmentPlan $a, InstallmentPlan $b) => [
            $a->cancelled_at !== null, (int) $a->projected_count === 0, -$a->purchase_date->timestamp, -$a->id,
        ] <=> [
            $b->cancelled_at !== null, (int) $b->projected_count === 0, -$b->purchase_date->timestamp, -$b->id,
        ])->values();
    }

    /**
     * @return Builder<InstallmentPlan>
     */
    private function baseQuery(): Builder
    {
        $projected = fn ($q) => $q->where('status', TransactionStatus::Projected->value);
        // Restante e próxima data só contam parcelas projetadas não ignoradas:
        // uma parcela ignorada não entra no que ainda falta pagar.
        $activeProjected = fn ($q) => $projected($q)->where('is_ignored', false);

        return InstallmentPlan::query()
            ->withCount([
                'transactions as posted_count' => fn ($q) => $q->where('status', '!=', TransactionStatus::Projected->value),
                'transactions as projected_count' => $projected,
            ])
            ->withSum(['transactions as remaining_amount' => $activeProjected], 'amount')
            ->withMin(['transactions as next_date' => $activeProjected], 'date')
            ->addSelect(['category_id' => Transaction::query()->withoutGlobalScopes()
                ->select('category_id')
                ->whereColumn('installment_plan_id', 'installment_plans.id')
                ->orderByDesc('installment_number')
                ->limit(1)])
            // Ordem final (cancelado/quitado por último) é feita em PHP por
            // sort(): ver o porquê no docblock de sort(). Esta ordem só serve
            // de desempate estável para quem lê a query direto (find()).
            ->orderByDesc('purchase_date')
            ->orderByDesc('id');
    }
}
