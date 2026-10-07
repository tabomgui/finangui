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

        $base = fn () => CardStatement::query()->withTotals()
            ->whereIn('card_statements.account_id', $accountIds);

        // DISTINCT ON (Postgres): uma linha por conta, a de menor closing_date
        // entre as fechadas que ainda devem dinheiro — pelo total exibido
        // (App\Domain\Cards\Models\CardStatement::total(): reported_total
        // quando informado, senão charges_net), nunca pelo calculado puro:
        // uma fatura fechada com reported_total menor que o calculado (ou
        // já quitada pelo banco) não pode continuar aparecendo como "atual"
        // só porque o cálculo local ainda mostra saldo.
        $closedWithDebt = DB::query()->fromSub($base(), 'cs')
            ->where('cs.closing_date', '<=', $today->toDateString())
            ->whereRaw('(COALESCE(cs.reported_total, cs.charges_net) - cs.payments_sum) > 0')
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
