<?php

namespace App\Domain\Cards\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cartões com saldo, uso do limite e a fatura atual (a próxima a vencer)
 * carregados em poucas queries, sem N+1.
 */
final class CardOverview
{
    /**
     * @return Collection<int, Account>
     */
    public function list(bool $includeArchived): Collection
    {
        $cards = Account::query()
            ->withBalance()
            ->withCardUsage()
            ->where('type', AccountType::CreditCard->value)
            ->when(! $includeArchived, fn ($q) => $q->where('is_archived', false))
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get();

        return $this->attachCurrentStatements($cards);
    }

    public function find(int $accountId): Account
    {
        $card = Account::query()->withBalance()->withCardUsage()
            ->where('type', AccountType::CreditCard->value)
            ->findOrFail($accountId);

        return $this->attachCurrentStatements(new Collection([$card]))->firstOrFail();
    }

    /**
     * Fatura atual de cada cartão: a mais antiga já fechada que ainda deve
     * dinheiro; sem nenhuma, a próxima a vencer. Duas consultas (uma para
     * cada candidata, via DISTINCT ON do Postgres) continuam independentes
     * da quantidade de cartões — sem N+1.
     *
     * @param  Collection<int, Account>  $cards
     * @return Collection<int, Account>
     */
    private function attachCurrentStatements(Collection $cards): Collection
    {
        $today = CarbonImmutable::today();
        $accountIds = $cards->modelKeys();

        $base = fn () => CardStatement::query()->withHistoryContext()
            ->whereIn('card_statements.account_id', $accountIds);

        // Dívida ("saldo em aberto") de uma fatura fechada, com o mesmo piso
        // de pago que CardStatement::paid() (o maior entre payments_sum e
        // reported_paid — uma fatura de antes do histórico sincronizado,
        // sem pagamento local nenhum, mas já quitada segundo o banco, não
        // pode continuar "devendo" aqui só por falta de lançamento local).
        $debtExpr = '(COALESCE(cs.reported_total, cs.charges_net) - GREATEST(cs.payments_sum, COALESCE(cs.reported_paid, 0)))';

        // "Totalmente paga" exige, além de saldo <= 0, um total maior que
        // zero: uma fatura fechada sem nenhuma cobrança (ciclo vazio — o
        // cartão não foi usado naquele mês) nunca pode servir de piso — ela
        // não "pagou" nada, e deixaria uma fatura antiga de verdade, ainda
        // em aberto, escondida atrás dela só por ser mais recente.
        $fullyPaidExpr = "({$debtExpr} <= 0 AND COALESCE(cs.reported_total, cs.charges_net) > 0)";

        // Fechamento mais recente, entre as fechadas totalmente pagas, de
        // cada conta: calculado uma vez por conta via window function
        // (MAX(...) FILTER ... OVER (PARTITION BY account_id)) em vez de um
        // correlacionado reconstruído a cada linha de $closedWithDebt —
        // cada fatura já carrega o fechamento da última paga da própria
        // conta pronto para o filtro abaixo usar.
        $withLatestPaidClosing = DB::query()->fromSub($base(), 'cs')->selectRaw(
            "cs.*, MAX(CASE WHEN cs.closing_date <= ? AND {$fullyPaidExpr} THEN cs.closing_date END) OVER (PARTITION BY cs.account_id) AS latest_paid_closing",
            [$today->toDateString()],
        );

        // DISTINCT ON (Postgres): uma linha por conta, a de menor closing_date
        // entre as fechadas que ainda devem dinheiro (pelo total exibido —
        // App\Domain\Cards\Models\CardStatement::total(): reported_total
        // quando informado, senão charges_net) e mais recente que a última
        // fechada já totalmente paga da mesma conta — uma fatura antiga
        // parcialmente paga (ou sem pagamento nenhum, ex.: import malformado,
        // dado de antes do histórico) nunca volta a ser a "atual" depois que
        // o usuário já pagou em cheio uma fatura mais nova: card companies
        // sempre aplicam pagamento à fatura mais antiga primeiro, então uma
        // fatura nova paga implica que a antiga também já foi resolvida de
        // algum jeito, mesmo que o cálculo local (sem todo o histórico)
        // ainda mostre saldo nela. Sem nenhuma fatura paga ainda para a
        // conta, o COALESCE cai numa data bem no passado — nunca filtra
        // nada nesse caso.
        $closedWithDebt = DB::query()->fromSub($withLatestPaidClosing, 'cs')
            ->where('cs.closing_date', '<=', $today->toDateString())
            ->whereRaw("{$debtExpr} > 0")
            ->whereRaw("cs.closing_date > COALESCE(cs.latest_paid_closing, '0001-01-01')")
            ->orderBy('cs.account_id')
            ->orderBy('cs.closing_date')
            ->distinct(['cs.account_id'])
            ->get();

        // Sem nenhuma fatura fechada em aberto: a próxima a vencer.
        $nextDue = DB::query()->fromSub($base(), 'cs')
            ->where('cs.due_date', '>=', $today->toDateString())
            ->orderBy('cs.account_id')
            ->orderBy('cs.due_date')
            ->distinct(['cs.account_id'])
            ->get();

        $rowsByAccount = $nextDue->keyBy('account_id');
        foreach ($closedWithDebt as $row) {
            $rowsByAccount->put($row->account_id, $row);
        }

        $current = CardStatement::query()
            ->hydrate($rowsByAccount->map(fn (object $row) => (array) $row)->values()->all())
            ->keyBy('account_id');

        return $cards->each(fn (Account $card) => $card->setRelation('currentStatement', $current->get($card->id)));
    }
}
