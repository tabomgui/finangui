<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     *     projected_balance?: int,
     * }
     */
    public function for(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        $now = CarbonImmutable::now();
        $isCurrentMonth = $month->isSameMonth($now);
        $asOf = $isCurrentMonth ? $now : $month->endOfMonth();

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
        ] + $this->projectedBalance($month, $now, $end, $primaryCurrency, $isCurrentMonth ? null : $accounts);
    }

    /**
     * Saldo previsto de fim de mês: só para o mês atual e o próximo (chave
     * ausente em meses passados e a partir de dois meses no futuro, nunca
     * null — ver CLAUDE.md). Parte do saldo lançado (moeda principal, contas
     * não arquivadas) até o fim do mês consultado — não "de hoje", para não
     * perder uma transação já lançada com data futura dentro do mês — e
     * soma, com sinal, as previstas e pendentes não ignoradas de contas da
     * moeda principal com data até o fim do mês consultado — inclui
     * previstas já atrasadas e parcelas projetadas.
     *
     * @param  Collection<int, Account>|null  $accountsAsOfEnd  contas já carregadas com withBalance() até o fim deste mesmo mês (ver for()); null força uma consulta nova.
     * @return array{projected_balance: int}|array{}
     */
    private function projectedBalance(CarbonImmutable $month, CarbonImmutable $now, string $end, string $primaryCurrency, ?Collection $accountsAsOfEnd): array
    {
        $startOfMonth = $month->startOfMonth();
        $currentMonthStart = $now->startOfMonth();

        if ($startOfMonth->lessThan($currentMonthStart) || $startOfMonth->greaterThan($currentMonthStart->addMonthNoOverflow())) {
            return [];
        }

        $accounts = $accountsAsOfEnd ?? Account::query()
            ->withBalance(CarbonImmutable::parse($end))
            ->where('is_archived', false)
            ->get();

        $endBalance = (int) $accounts->where('currency', $primaryCurrency)->sum(fn (Account $a) => $a->balance()->cents);

        $pending = Transaction::query()
            ->whereIn('transactions.status', [TransactionStatus::Projected->value, TransactionStatus::Pending->value])
            ->where('transactions.is_ignored', false)
            ->where('transactions.date', '<=', $end)
            ->whereHas('account', fn (Builder $q) => $q->where('is_archived', false)->where('currency', $primaryCurrency))
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
            ->toBase()
            ->first();

        return ['projected_balance' => $endBalance + (int) ($pending->net ?? 0)];
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
