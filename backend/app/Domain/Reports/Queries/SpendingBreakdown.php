<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Categories\Models\Category;
use App\Domain\Reports\Data\SpendingBreakdownResult;
use App\Domain\Reports\Data\SpendingCategoryItem;
use App\Domain\Reports\Data\SpendingChildItem;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Distribuição de despesa por categoria num período: mesma base de "Despesas"
 * da Início (Transaction::reportable(), direction=out, moeda principal, pela
 * data do lançamento). Cada categoria-raiz traz o detalhamento por
 * subcategoria em children — uma entrada por subcategoria com gasto, mais,
 * se houver gasto lançado direto na categoria pai (não em nenhuma
 * subcategoria), uma entrada extra com categoryId = id do pai e direct =
 * true. children só existe quando pelo menos uma subcategoria tem gasto: só
 * direto no pai, sem nenhuma subcategoria gasta, não forma detalhamento
 * (children vazio) — não há nada para abrir.
 */
final class SpendingBreakdown
{
    public function for(CarbonImmutable $from, CarbonImmutable $to): SpendingBreakdownResult
    {
        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        $start = $from->toDateString();
        $end = $to->toDateString();

        $base = Transaction::query()
            ->reportable()
            ->where('transactions.direction', 'out')
            ->where('transactions.currency', $primaryCurrency)
            ->whereBetween('transactions.date', [$start, $end]);

        /** @var Collection<int, object{category_id: int, total: int, count: int}> $categorizedRows */
        $categorizedRows = (clone $base)
            ->whereNotNull('transactions.category_id')
            ->selectRaw('transactions.category_id as category_id, SUM(transactions.amount) as total, COUNT(*) as count')
            ->groupBy('transactions.category_id')
            ->toBase()
            ->get();

        $uncategorized = (clone $base)
            ->whereNull('transactions.category_id')
            ->selectRaw('SUM(transactions.amount) as total, COUNT(*) as count')
            ->toBase()
            ->first();

        $categories = $this->loadCategories($categorizedRows);

        $items = $this->buildCategoryItems($categorizedRows, $categories);

        $uncategorizedCount = (int) ($uncategorized->count ?? 0);
        if ($uncategorizedCount > 0) {
            $items->push(new SpendingCategoryItem(
                categoryId: null,
                name: 'Sem categoria',
                color: null,
                icon: null,
                amount: (int) $uncategorized->total,
                count: $uncategorizedCount,
                children: [],
            ));
        }

        $items = $items->sortByDesc(fn (SpendingCategoryItem $item) => $item->amount)->values();

        return new SpendingBreakdownResult(
            currency: $primaryCurrency,
            total: (int) $items->sum('amount'),
            categories: $items->all(),
        );
    }

    /**
     * Carrega as categorias com gasto direto e, quando faltar, o pai delas
     * (precisa de nome/ícone/cor mesmo sem gasto direto no pai, só com
     * gasto nas subcategorias).
     *
     * @param  Collection<int, object{category_id: int, total: int, count: int}>  $rows
     * @return Collection<int, Category>
     */
    private function loadCategories(Collection $rows): Collection
    {
        $ids = $rows->map(fn (object $row) => (int) $row->category_id);

        $categories = Category::query()->whereIn('id', $ids)->get()->keyBy('id');

        $missingParentIds = $categories->pluck('parent_id')
            ->filter()
            ->reject(fn (int $id) => $categories->has($id))
            ->unique();

        if ($missingParentIds->isNotEmpty()) {
            $categories = $categories->union(Category::query()->whereIn('id', $missingParentIds)->get()->keyBy('id'));
        }

        return $categories;
    }

    /**
     * @param  Collection<int, object{category_id: int, total: int, count: int}>  $rows
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, SpendingCategoryItem>
     */
    private function buildCategoryItems(Collection $rows, Collection $categories): Collection
    {
        /** @var Collection<int, array{amount: int, count: int}> $directByRoot */
        $directByRoot = collect();
        /** @var Collection<int, Collection<int, SpendingChildItem>> $childrenByRoot */
        $childrenByRoot = collect();

        foreach ($rows as $row) {
            $category = $categories->get((int) $row->category_id);

            if ($category === null) {
                continue;
            }

            $amount = (int) $row->total;
            $count = (int) $row->count;

            if ($category->parent_id === null) {
                // Gasto lançado direto na própria categoria-raiz.
                $directByRoot->put($category->id, ['amount' => $amount, 'count' => $count]);

                continue;
            }

            $rootId = $category->parent_id;
            $children = $childrenByRoot->get($rootId) ?? collect();
            $children->push(new SpendingChildItem(
                categoryId: $category->id,
                name: $category->name,
                color: $category->color,
                icon: $category->icon,
                amount: $amount,
                count: $count,
                direct: false,
            ));
            $childrenByRoot->put($rootId, $children);
        }

        $rootIds = $directByRoot->keys()->merge($childrenByRoot->keys())->unique();

        return $rootIds->map(function (int $rootId) use ($directByRoot, $childrenByRoot, $categories) {
            $rootCategory = $categories->get($rootId);
            $children = $childrenByRoot->get($rootId) ?? collect();
            $direct = $directByRoot->get($rootId);

            $amount = (int) $children->sum('amount');
            $count = (int) $children->sum('count');

            if ($direct !== null) {
                $amount += $direct['amount'];
                $count += $direct['count'];

                // Direto no pai só entra no detalhamento quando já existe
                // alguma subcategoria com gasto — ver docblock da classe.
                if ($children->isNotEmpty() && $rootCategory !== null) {
                    $children = $children->push(new SpendingChildItem(
                        categoryId: $rootCategory->id,
                        name: $rootCategory->name,
                        color: $rootCategory->color,
                        icon: $rootCategory->icon,
                        amount: $direct['amount'],
                        count: $direct['count'],
                        direct: true,
                    ));
                }
            }

            $children = $children->sortByDesc(fn (SpendingChildItem $child) => $child->amount)->values();

            return new SpendingCategoryItem(
                categoryId: $rootCategory?->id,
                name: $rootCategory->name ?? 'Sem categoria',
                color: $rootCategory?->color,
                icon: $rootCategory?->icon,
                amount: $amount,
                count: $count,
                children: $children->all(),
            );
        });
    }
}
