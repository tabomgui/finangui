<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Categories\Models\Category;
use App\Domain\Reports\Data\CategoryComparisonItem;
use App\Domain\Reports\Data\CategoryComparisonResult;
use App\Domain\Reports\Enums\ReportBasis;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Comparação de despesa por categoria entre dois períodos (A e B): duas
 * consultas agregadas (uma por período, Transaction::reportable(), moeda
 * principal), somando cada subcategoria na categoria-raiz (filhas somadas no
 * pai) e separando "Sem categoria". basis segue a mesma regra de
 * App\Domain\Reports\Queries\MonthlyEvolution.
 */
final class CategoryComparison
{
    /**
     * Sentinela para "sem categoria": ids de categoria começam em 1, nunca
     * colide com uma categoria real. Usado só como chave interna — nunca sai
     * na resposta (category_id vai null nesse caso).
     */
    private const NO_CATEGORY = 0;

    public function for(CarbonImmutable $aFrom, CarbonImmutable $aTo, CarbonImmutable $bFrom, CarbonImmutable $bTo, ReportBasis $basis): CategoryComparisonResult
    {
        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        $aTotals = $this->totalsByRootCategory($aFrom, $aTo, $basis, $primaryCurrency);
        $bTotals = $this->totalsByRootCategory($bFrom, $bTo, $basis, $primaryCurrency);

        /** @var Collection<int, int> $ids */
        $ids = $aTotals->keys()->merge($bTotals->keys())->unique()->values();

        $categories = Category::query()
            ->whereIn('id', $ids->reject(fn (int $id) => $id === self::NO_CATEGORY))
            ->get()
            ->keyBy('id');

        $items = $ids
            ->map(function (int $id) use ($aTotals, $bTotals, $categories) {
                $category = $id !== self::NO_CATEGORY ? $categories->get($id) : null;
                $a = (int) ($aTotals->get($id) ?? 0);
                $b = (int) ($bTotals->get($id) ?? 0);

                return new CategoryComparisonItem(
                    categoryId: $category?->id,
                    name: $category->name ?? 'Sem categoria',
                    icon: $category?->icon,
                    color: $category?->color,
                    a: $a,
                    b: $b,
                    delta: $b - $a,
                );
            })
            ->sortByDesc(fn (CategoryComparisonItem $item) => max($item->a, $item->b))
            ->values();

        $a = (int) $items->sum('a');
        $b = (int) $items->sum('b');

        return new CategoryComparisonResult(
            $primaryCurrency,
            $items->all(),
            ['a' => $a, 'b' => $b, 'delta' => $b - $a],
        );
    }

    /**
     * Despesas por categoria-raiz num único select (sem N+1 por categoria).
     *
     * @return Collection<int, int>
     */
    private function totalsByRootCategory(CarbonImmutable $from, CarbonImmutable $to, ReportBasis $basis, string $primaryCurrency): Collection
    {
        $start = $from->startOfMonth()->toDateString();
        $end = $to->endOfMonth()->toDateString();
        $dateExpression = $this->dateExpression($basis);

        $query = Transaction::query()
            ->reportable()
            ->where('transactions.direction', 'out')
            ->where('transactions.currency', $primaryCurrency)
            // Join bruto: ignora o global scope por usuário de Category, mas é
            // seguro porque category_id é validado no write para pertencer ao
            // mesmo usuário da transação, e os nomes exibidos vêm da query
            // escopada em for() (mesma justificativa de MonthSummary::topCategories).
            ->leftJoin('categories as c', 'c.id', '=', 'transactions.category_id');

        if ($basis === ReportBasis::Statement) {
            // Join bruto, sem global scope de CardStatement: seguro pelo mesmo
            // motivo de MonthlyEvolution — statement_id só aponta para uma
            // fatura do próprio usuário.
            $query->leftJoin('card_statements as cs', 'cs.id', '=', 'transactions.statement_id');
        }

        $rows = $query->whereRaw("({$dateExpression}) between ? and ?", [$start, $end])
            ->selectRaw('COALESCE(c.parent_id, c.id) as root_id, SUM(transactions.amount) as total')
            ->groupByRaw('COALESCE(c.parent_id, c.id)')
            ->toBase()
            ->get();

        return $rows->mapWithKeys(fn (object $row) => [
            $row->root_id !== null ? (int) $row->root_id : self::NO_CATEGORY => (int) $row->total,
        ]);
    }

    private function dateExpression(ReportBasis $basis): string
    {
        return $basis === ReportBasis::Statement ? 'COALESCE(cs.due_date, transactions.date)' : 'transactions.date';
    }
}
