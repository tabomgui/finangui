<?php

namespace App\Domain\Cards\Queries;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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
     * @param  Collection<int, Account>  $cards
     * @return Collection<int, Account>
     */
    private function attachCurrentStatements(Collection $cards): Collection
    {
        $current = CardStatement::query()->withTotals()
            ->whereIn('account_id', $cards->modelKeys())
            ->where('due_date', '>=', CarbonImmutable::today()->toDateString())
            ->orderBy('due_date')
            ->get()
            ->unique('account_id')
            ->keyBy('account_id');

        return $cards->each(fn (Account $card) => $card->setRelation('currentStatement', $current->get($card->id)));
    }
}
