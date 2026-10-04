<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Data\MonthlyEvolutionResult;
use App\Domain\Reports\Enums\ReportBasis;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Evolução mensal de receita/despesa/líquido: uma única consulta agregada
 * para o período inteiro (Transaction::reportable(), moeda principal), com
 * os meses sem lançamento preenchidos com zero depois. basis=purchase agrupa
 * pela data do lançamento; basis=statement move as transações de cartão para
 * o mês do vencimento da fatura ligada (card_statements.due_date via
 * transactions.statement_id) — sem fatura vinculada (ou fora de cartão,
 * nunca tem statement_id) continua pela própria data.
 */
final class MonthlyEvolution
{
    public function for(CarbonImmutable $from, CarbonImmutable $to, ReportBasis $basis): MonthlyEvolutionResult
    {
        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        $start = $from->startOfMonth()->toDateString();
        $end = $to->endOfMonth()->toDateString();
        $dateExpression = $this->dateExpression($basis);

        $query = Transaction::query()->reportable()->where('transactions.currency', $primaryCurrency);

        if ($basis === ReportBasis::Statement) {
            // Join bruto, sem global scope de CardStatement: seguro porque toda
            // linha já vem de transactions filtrado pelo usuário atual (próprio
            // global scope de Transaction), e statement_id só aponta para uma
            // fatura do mesmo usuário (AssignStatement garante isso na escrita).
            $query->leftJoin('card_statements as cs', 'cs.id', '=', 'transactions.statement_id');
        }

        $rows = $query->whereRaw("({$dateExpression}) between ? and ?", [$start, $end])
            ->selectRaw("to_char({$dateExpression}, 'YYYY-MM') as month")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE direction = 'in'), 0) AS income")
            ->selectRaw("COALESCE(SUM(amount) FILTER (WHERE direction = 'out'), 0) AS expense")
            ->groupByRaw("to_char({$dateExpression}, 'YYYY-MM')")
            ->toBase()
            ->get()
            ->keyBy('month');

        return new MonthlyEvolutionResult($primaryCurrency, $this->fillMonths($from, $to, $rows));
    }

    /**
     * @param  Collection<array-key, stdClass>  $rows
     * @return list<array{month: string, income: int, expense: int, net: int}>
     */
    private function fillMonths(CarbonImmutable $from, CarbonImmutable $to, Collection $rows): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last = $to->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->format('Y-m');
            $row = $rows->get($key);
            $income = (int) ($row->income ?? 0);
            $expense = (int) ($row->expense ?? 0);

            $months[] = ['month' => $key, 'income' => $income, 'expense' => $expense, 'net' => $income - $expense];

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    private function dateExpression(ReportBasis $basis): string
    {
        return $basis === ReportBasis::Statement ? 'COALESCE(cs.due_date, transactions.date)' : 'transactions.date';
    }
}
