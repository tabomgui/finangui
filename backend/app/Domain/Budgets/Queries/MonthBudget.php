<?php

namespace App\Domain\Budgets\Queries;

use App\Domain\Budgets\Data\MonthBudgetResult;
use App\Domain\Budgets\Models\Budget;
use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MonthBudget
{
    /**
     * Orçamento por categoria de um mês: valor (padrão ou exceção), gasto,
     * restante e progresso, sempre derivados — nunca gravados. Uma categoria
     * sem filhos usa só o próprio gasto; uma categoria-pai soma o dela com o
     * das filhas (a filha pode também ter orçamento próprio, contando nos
     * dois). Só três consultas no total (categorias, orçamentos do mês,
     * gasto por categoria), sem N+1 por item orçado.
     */
    public function for(CarbonImmutable $month): MonthBudgetResult
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        $categories = Category::query()->get()->keyBy('id');

        $budgets = Budget::query()
            ->where(fn ($q) => $q->whereNull('month')->orWhere('month', $start))
            ->get();
        $defaults = $budgets->whereNull('month')->keyBy('category_id');
        $overrides = $budgets->whereNotNull('month')->keyBy('category_id');

        // Uma exceção de valor 0 cancela o orçamento daquele mês (ver
        // SaveBudget/SaveBudgetRequest): só entra em items/totals, e só
        // conta para "esta categoria está orçada" nas regras abaixo, quem
        // de fato tem valor positivo neste mês.
        /** @var Collection<int, int> $activeIds */
        $activeIds = $budgets->pluck('category_id')->unique()->values()
            ->filter(function (int $categoryId) use ($defaults, $overrides) {
                $budget = $overrides->get($categoryId) ?? $defaults->get($categoryId);

                return $budget !== null && $budget->amount->cents > 0;
            })
            ->values();

        $nets = $this->netsByCategory($start, $end, $primaryCurrency);
        $noCategoryNet = $this->noCategoryNet($start, $end, $primaryCurrency);

        $items = $activeIds
            ->map(function (int $categoryId) use ($categories, $defaults, $overrides, $nets) {
                $category = $categories->get($categoryId);

                if ($category === null || $this->isTransferCategory($category, $categories)) {
                    return null;
                }

                $override = $overrides->get($categoryId);
                $budget = $override ?? $defaults->get($categoryId);

                if ($budget === null) {
                    return null;
                }

                $scopeIds = [$categoryId, ...$categories->where('parent_id', $categoryId)->pluck('id')->all()];
                $spent = max(array_sum(array_map(fn (int $id) => $nets->get($id, 0), $scopeIds)), 0);
                $amount = $budget->amount->cents;

                return [
                    'category' => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon,
                        'color' => $category->color,
                    ],
                    'amount' => $amount,
                    'source' => $override !== null ? 'override' : 'default',
                    'spent' => $spent,
                    'remaining' => $amount - $spent,
                    'percent' => $amount > 0 ? (int) floor($spent * 100 / $amount) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('percent')
            ->values();

        // Totais ignoram o item cujo pai também está orçado (de verdade,
        // valor > 0) neste mês: o gasto da filha já entra no total do pai
        // via scopeIds acima, somar os dois de novo contaria em dobro.
        $totalsIds = $activeIds->reject(function (int $categoryId) use ($categories, $activeIds) {
            $parentId = $categories->get($categoryId)?->parent_id;

            return $parentId !== null && $activeIds->contains($parentId);
        });
        $totalsItems = $items->whereIn('category.id', $totalsIds->all());

        $unbudgetedSpent = $categories
            ->reject(fn (Category $category) => $category->kind !== CategoryKind::Expense
                || $activeIds->contains($category->id)
                || ($category->parent_id !== null && $activeIds->contains($category->parent_id)))
            // Cada categoria clampada a 0 antes de somar: um estorno maior
            // que o gasto (líquido negativo) não pode abater o total de
            // outra categoria sem orçamento.
            ->sum(fn (Category $category) => max($nets->get($category->id, 0), 0));

        $budgeted = (int) $totalsItems->sum('amount');
        $spent = (int) $totalsItems->sum('spent');

        return new MonthBudgetResult(
            month: $month->format('Y-m'),
            currency: $primaryCurrency,
            items: $items->all(),
            totals: [
                'budgeted' => $budgeted,
                'spent' => $spent,
                'remaining' => $budgeted - $spent,
            ],
            unbudgetedSpent: (int) $unbudgetedSpent + $noCategoryNet,
        );
    }

    /**
     * Saídas menos entradas (estornos) por categoria, num só select — evita
     * uma consulta por categoria orçada.
     *
     * @return Collection<int, int>
     */
    private function netsByCategory(string $start, string $end, string $primaryCurrency): Collection
    {
        $rows = Transaction::query()
            ->reportable()
            ->whereBetween('transactions.date', [$start, $end])
            ->where('transactions.currency', $primaryCurrency)
            ->whereNotNull('transactions.category_id')
            ->groupBy('transactions.category_id')
            ->selectRaw("transactions.category_id as category_id, COALESCE(SUM(CASE WHEN transactions.direction = 'out' THEN transactions.amount ELSE -transactions.amount END), 0) AS net")
            ->toBase()
            ->get();

        return $rows->mapWithKeys(fn (object $row) => [(int) $row->category_id => (int) $row->net]);
    }

    /**
     * Só saídas: uma entrada sem categoria não é estorno de nada (não há
     * categoria para ela abater), diferente do líquido por categoria acima.
     */
    private function noCategoryNet(string $start, string $end, string $primaryCurrency): int
    {
        $row = Transaction::query()
            ->reportable()
            ->whereBetween('transactions.date', [$start, $end])
            ->where('transactions.currency', $primaryCurrency)
            ->where('transactions.direction', 'out')
            ->whereNull('transactions.category_id')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) AS net')
            ->toBase()
            ->first();

        return (int) ($row->net ?? 0);
    }

    /**
     * Uma categoria (ou o pai dela) pode virar transferência depois que um
     * orçamento já existia; o item correspondente some da resposta, sem
     * apagar o Budget em si (volta a aparecer se a categoria deixar de ser
     * de transferência).
     *
     * @param  Collection<int, Category>  $categories
     */
    private function isTransferCategory(Category $category, Collection $categories): bool
    {
        if ($category->is_transfer) {
            return true;
        }

        $parent = $category->parent_id !== null ? $categories->get($category->parent_id) : null;

        // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que Collection::get() pode voltar null; em runtime o pai pode não estar carregado)
        return $parent?->is_transfer ?? false;
    }
}
