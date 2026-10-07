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

        // Uma única consulta agregada: GROUP BY category_id junta as linhas
        // sem categoria (NULL) num grupo só — essa linha vira o item "Sem
        // categoria" mais abaixo.
        /** @var Collection<int, object{category_id: int|null, total: int, count: int}> $rows */
        $rows = Transaction::query()
            ->reportable()
            ->where('transactions.direction', 'out')
            ->where('transactions.currency', $primaryCurrency)
            ->whereBetween('transactions.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('transactions.category_id as category_id, SUM(transactions.amount) as total, COUNT(*) as count')
            ->groupBy('transactions.category_id')
            ->toBase()
            ->get();

        $categorizedRows = $rows->reject(fn (object $row) => $row->category_id === null);
        $uncategorizedRow = $rows->first(fn (object $row) => $row->category_id === null);

        $categories = $this->loadCategories($categorizedRows);

        $items = $this->buildCategoryItems($categorizedRows, $categories);

        if ($uncategorizedRow !== null) {
            $items->push(new SpendingCategoryItem(
                categoryId: null,
                name: 'Sem categoria',
                color: null,
                icon: null,
                amount: (int) $uncategorizedRow->total,
                count: (int) $uncategorizedRow->count,
                children: [],
            ));
        }

        $items = $this->sorted($items);

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
     * @param  Collection<int, object{category_id: int|null, total: int, count: int}>  $rows  category_id nunca é null aqui de fato (a linha "Sem categoria" já foi separada em for()), mas reject() não estreita o tipo estático
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
     * @param  Collection<int, object{category_id: int|null, total: int, count: int}>  $rows  ver loadCategories() sobre o tipo nullable
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
            /** @var Collection<int, SpendingChildItem> $children */
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

        return $rootIds
            ->map(function (int $rootId) use ($directByRoot, $childrenByRoot, $categories) {
                $rootCategory = $categories->get($rootId);

                // Pai inalcançável (parent_id apontando para uma categoria
                // que não existe mais): não é um caso de "Sem categoria",
                // só não há como montar o item — pula a linha.
                if ($rootCategory === null) {
                    return null;
                }

                /** @var Collection<int, SpendingChildItem> $children */
                $children = $childrenByRoot->get($rootId) ?? collect();
                $direct = $directByRoot->get($rootId);

                $amount = (int) $children->sum('amount');
                $count = (int) $children->sum('count');

                if ($direct !== null) {
                    $amount += $direct['amount'];
                    $count += $direct['count'];

                    // Direto no pai só entra no detalhamento quando já existe
                    // alguma subcategoria com gasto — ver docblock da classe.
                    if ($children->isNotEmpty()) {
                        $children->push(new SpendingChildItem(
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

                return new SpendingCategoryItem(
                    categoryId: $rootCategory->id,
                    name: $rootCategory->name,
                    color: $rootCategory->color,
                    icon: $rootCategory->icon,
                    amount: $amount,
                    count: $count,
                    children: $this->sorted($children)->all(),
                );
            })
            ->filter();
    }

    /**
     * Valor desc, nome asc como critério de desempate (duas categorias/
     * subcategorias com o mesmo gasto saem em ordem estável e previsível).
     *
     * @template TItem of SpendingCategoryItem|SpendingChildItem
     *
     * @param  Collection<int, TItem>  $items
     * @return Collection<int, TItem>
     */
    private function sorted(Collection $items): Collection
    {
        return $items->sortBy([['amount', 'desc'], ['name', 'asc']])->values();
    }
}
