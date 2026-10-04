<?php

namespace App\Domain\Recurrences\Queries;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Previstas de recorrência com data < hoje - 5 dias: candidatas a "não
 * aconteceu?" (confirmar ou pular). Uma prevista ignorada não é pendência,
 * e uma prevista com external_id já deixou de ser uma prevista "livre"
 * (ver IngestionPlanner::isProjectedRecurrenceOccurrence()) — não deveria
 * acontecer (RecurrenceMatcher é o único caminho até ela, e ele sempre
 * confirma/posta ao casar), mas o filtro fica aqui por segurança.
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
            ->whereNotNull('recurrence_id')
            ->whereNull('external_id')
            ->where('status', TransactionStatus::Projected->value)
            ->where('is_ignored', false)
            ->where('recurrence_date', '<', $today->subDays(5)->toDateString())
            ->with(['account', 'category.parent', 'recurrence'])
            ->orderBy('recurrence_date')
            ->get();
    }
}
