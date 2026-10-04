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
        /** @var Collection<int, int> $budgetedIds */
        $budgetedIds = $budgets->pluck('category_id')->unique()->values();

        $nets = $this->netsByCategory($start, $end, $primaryCurrency);
        $noCategoryNet = $this->noCategoryNet($start, $end, $primaryCurrency);

        $items = $budgetedIds
            ->map(function (int $categoryId) use ($categories, $defaults, $overrides, $nets) {
                $category = $categories->get($categoryId);

                if ($category === null) {
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
                    'percent' => $amount > 0 ? (int) round($spent * 100 / $amount) : 0,
                ];
            })
            ->filter()
            ->sortByDesc('percent')
            ->values();

        $unbudgetedSpent = $categories
            ->reject(fn (Category $category) => $category->kind !== CategoryKind::Expense
                || $budgetedIds->contains($category->id)
                || ($category->parent_id !== null && $budgetedIds->contains($category->parent_id)))
            ->sum(fn (Category $category) => $nets->get($category->id, 0));

        return new MonthBudgetResult(
            month: $month->format('Y-m'),
            currency: $primaryCurrency,
            items: $items->all(),
            totals: [
                'budgeted' => (int) $items->sum('amount'),
                'spent' => (int) $items->sum('spent'),
            ],
            unbudgetedSpent: max((int) $unbudgetedSpent + $noCategoryNet, 0),
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

    private function noCategoryNet(string $start, string $end, string $primaryCurrency): int
    {
        $row = Transaction::query()
            ->reportable()
            ->whereBetween('transactions.date', [$start, $end])
            ->where('transactions.currency', $primaryCurrency)
            ->whereNull('transactions.category_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN transactions.direction = 'out' THEN transactions.amount ELSE -transactions.amount END), 0) AS net")
            ->toBase()
            ->first();

        return (int) ($row->net ?? 0);
    }
}
