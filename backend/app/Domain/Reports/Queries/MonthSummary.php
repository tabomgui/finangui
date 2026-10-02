<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

final class MonthSummary
{
    private const TOP_CATEGORIES = 5;

    /**
     * @return array{
     *     month: string,
     *     currency: string,
     *     total_balance: int,
     *     balance_date: string,
     *     accounts: list<array{id: int, name: string, type: AccountType, currency: string, color: string|null, icon: string|null, balance: int}>,
     *     income: int,
     *     expense: int,
     *     net: int,
     *     top_categories: list<array{category_id: int|null, name: string, icon: string|null, color: string|null, amount: int}>,
     * }
     */
    public function for(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        $now = CarbonImmutable::now();
        $asOf = $month->isSameMonth($now) ? $now : $month->endOfMonth();

        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        $accounts = Account::query()->withBalance($asOf)->where('is_archived', false)->orderBy('name')->get();

        $totals = Transaction::query()
            ->reportable()
            ->whereBetween('transactions.date', [$start, $end])
            ->where('transactions.currency', $primaryCurrency)
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE direction = 'in'), 0) AS income")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE direction = 'out'), 0) AS expense")
            ->toBase()
            ->first();

        $income = (int) ($totals->income ?? 0);
        $expense = (int) ($totals->expense ?? 0);

        return [
            'month' => $month->format('Y-m'),
            'currency' => $primaryCurrency,
            'total_balance' => (int) $accounts->where('currency', $primaryCurrency)
                ->sum(fn (Account $a) => $a->balance()->cents),
            'balance_date' => $asOf->toDateString(),
            'accounts' => $accounts->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'color' => $a->color,
                'icon' => $a->icon,
                'balance' => $a->balance()->cents,
            ])->values()->all(),
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'top_categories' => $this->topCategories($start, $end, $primaryCurrency),
        ];
    }

    /**
     * @return list<array{category_id: int|null, name: string, icon: string|null, color: string|null, amount: int}>
     */
    private function topCategories(string $start, string $end, string $primaryCurrency): array
    {
        $rows = Transaction::query()
            ->reportable()
            ->whereBetween('transactions.date', [$start, $end])
            ->where('transactions.direction', 'out')
            ->where('transactions.currency', $primaryCurrency)
            // Join bruto: ignora o global scope por usuário de Category, mas é seguro
            // porque category_id é validado no write para pertencer ao mesmo usuário
            // da transação, e os nomes exibidos vêm da query escopada abaixo.
            ->leftJoin('categories as c', 'c.id', '=', 'transactions.category_id')
            ->selectRaw('COALESCE(c.parent_id, c.id) AS root_id, SUM(transactions.amount) AS total')
            ->groupByRaw('COALESCE(c.parent_id, c.id)')
            ->orderByDesc('total')
            ->limit(self::TOP_CATEGORIES)
            ->toBase()
            ->get();

        $categories = Category::query()->whereIn('id', $rows->pluck('root_id')->filter())->get()->keyBy('id');

        return $rows->map(function (object $row) use ($categories) {
            $category = $row->root_id !== null ? $categories->get($row->root_id) : null;

            return [
                'category_id' => $category !== null ? (int) $category->id : null,
                'name' => $category->name ?? 'Sem categoria',
                'icon' => $category?->icon,
                'color' => $category?->color,
                'amount' => (int) $row->total,
            ];
        })->values()->all();
    }
}
