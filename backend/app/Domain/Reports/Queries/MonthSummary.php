<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

final class MonthSummary
{
    /**
     * @return array{
     *     month: string,
     *     currency: string,
     *     total_balance: int,
     *     balance_date: string,
     *     today: string,
     *     accounts: list<array{id: int, name: string, type: AccountType, currency: string, color: string|null, icon: string|null, balance: int}>,
     *     income: int,
     *     expense: int,
     *     net: int,
     * }
     */
    public function for(CarbonImmutable $month, CarbonImmutable $balanceDate): array
    {
        $start = $month->startOfMonth()->toDateString();
        $end = $month->endOfMonth()->toDateString();

        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        // Cartão não entra no saldo da Início: a dívida dele aparece no card
        // de Faturas, não aqui (ver App\Domain\Cards\Queries\CardOverview).
        $accounts = Account::query()
            ->withBalance($balanceDate)
            ->where('is_archived', false)
            ->where('type', '!=', AccountType::CreditCard->value)
            ->orderBy('name')
            ->get();

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
            'balance_date' => $balanceDate->toDateString(),
            // Hoje no fuso do app, independente de $balanceDate (que pode ser um dia escolhido
            // pelo usuário): o frontend usa esta chave para desabilitar dias futuros no
            // calendário, "Voltar para hoje" e a lógica de "é hoje" — nunca o relógio do
            // navegador (ver dashboard-page.tsx).
            'today' => CarbonImmutable::now()->toDateString(),
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
        ];
    }
}
