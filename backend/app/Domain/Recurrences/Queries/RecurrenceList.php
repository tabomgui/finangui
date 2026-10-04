<?php

namespace App\Domain\Recurrences\Queries;

use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lista do usuário: ativas primeiro, depois por próxima data (a ocorrência
 * prevista mais próxima, hoje em diante); sem próxima data, por último.
 */
final class RecurrenceList
{
    /**
     * @return Collection<int, Recurrence>
     */
    public function all(): Collection
    {
        $today = CarbonImmutable::today()->toDateString();

        $recurrences = Recurrence::query()
            ->with(['account', 'category'])
            ->withMin(['transactions as next_date' => fn (Builder $query) => $query
                ->where('status', TransactionStatus::Projected->value)
                ->where('recurrence_date', '>=', $today)], 'recurrence_date')
            ->get();

        return $this->sort($recurrences);
    }

    /**
     * @param  Collection<int, Recurrence>  $recurrences
     * @return Collection<int, Recurrence>
     */
    private function sort(Collection $recurrences): Collection
    {
        return $recurrences->sort(fn (Recurrence $a, Recurrence $b) => [
            ! $a->is_active, $a->next_date ?? '9999-99-99', $a->id,
        ] <=> [
            ! $b->is_active, $b->next_date ?? '9999-99-99', $b->id,
        ])->values();
    }
}
