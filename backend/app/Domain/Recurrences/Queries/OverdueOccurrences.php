<?php

namespace App\Domain\Recurrences\Queries;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Previstas de recorrência com data < hoje - 5 dias: candidatas a "não
 * aconteceu?" (confirmar ou pular). Uma prevista ignorada não é pendência.
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
            ->where('status', TransactionStatus::Projected->value)
            ->where('is_ignored', false)
            ->where('recurrence_date', '<', $today->subDays(5)->toDateString())
            ->with(['account', 'category.parent', 'recurrence'])
            ->orderBy('recurrence_date')
            ->get();
    }
}
