<?php

namespace App\Domain\Recurrences\Queries;

use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ocorrências ainda não confirmadas (Transaction::isUnconfirmedOccurrence())
 * com data < hoje - 5 dias: candidatas a "não aconteceu?" (confirmar ou
 * pular). Uma prevista ignorada não é pendência, e uma de conta arquivada
 * também não — a conta já saiu do fluxo do usuário.
 */
final class OverdueOccurrences
{
    /**
     * @return Collection<int, Transaction>
     */
    public function handle(?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        return Transaction::query()
            ->unconfirmedOccurrences()
            ->where('is_ignored', false)
            ->where('recurrence_date', '<', $today->subDays(5)->toDateString())
            ->whereHas('account', fn ($query) => $query->where('is_archived', false))
            ->with(['account', 'category.parent', 'recurrence'])
            ->orderBy('recurrence_date')
            ->get();
    }
}
