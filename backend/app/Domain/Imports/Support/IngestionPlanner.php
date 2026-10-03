<?php

namespace App\Domain\Imports\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decide o destino de cada linha sem gravar nada (cascata de dedup, spec
 * 5.1/5.8): usado tanto pela prévia quanto pela confirmação, para garantir
 * que as duas vejam os mesmos números. Consulta só a conta de destino e uma
 * janela de datas em volta das linhas do arquivo.
 */
final class IngestionPlanner
{
    /**
     * @param  list<ParsedRow>  $rows
     * @return list<RowDecision>
     */
    public function plan(Account $account, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = array_map(fn (ParsedRow $row) => $row->date, $rows);
        $minDate = CarbonImmutable::parse(min($dates))->subDays(7)->toDateString();
        $maxDate = CarbonImmutable::parse(max($dates))->addDays(7)->toDateString();
        $externalIds = array_values(array_unique(array_map(fn (ParsedRow $row) => $row->externalId, $rows)));

        $windowed = Transaction::query()
            ->where('account_id', $account->id)
            ->where(function (Builder $query) use ($minDate, $maxDate, $externalIds) {
                $query->whereBetween('date', [$minDate, $maxDate])
                    ->orWhereIn('external_id', $externalIds);
            })
            ->get();

        $projectedInstallments = Transaction::query()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Projected->value)
            ->whereNotNull('installment_plan_id')
            ->with('installmentPlan')
            ->get();

        $candidates = $windowed->concat($projectedInstallments)->unique('id')->sortBy('id')->values();

        $byExternalId = $candidates->whereNotNull('external_id')->keyBy('external_id');
        $installmentPool = $candidates->filter(
            fn (Transaction $t) => $t->status === TransactionStatus::Projected && $t->installment_plan_id !== null
        )->values();
        // Parcelas (postadas ou projetadas) não são lançamentos manuais livres:
        // têm seu próprio casamento (replace_installment) e não devem ser
        // "adotadas" por engano só por também não terem external_id.
        $adoptionPool = $candidates->whereNull('external_id')->whereNull('installment_plan_id')->values();
        $swapPool = $candidates->filter(
            fn (Transaction $t) => $t->external_id !== null && $t->status === TransactionStatus::Pending
        )->values();

        $decisions = [];
        $usedIds = [];
        $seenExternalIds = [];

        foreach ($rows as $row) {
            if (isset($seenExternalIds[$row->externalId])) {
                $decisions[] = new RowDecision($row, RowOutcome::Duplicate);

                continue;
            }
            $seenExternalIds[$row->externalId] = true;

            $existing = $byExternalId->get($row->externalId);

            if ($existing !== null) {
                $decisions[] = $existing->status === TransactionStatus::Pending && ! $row->pending
                    ? new RowDecision($row, RowOutcome::Update, $existing->id)
                    : new RowDecision($row, RowOutcome::Duplicate, $existing->id);

                continue;
            }

            if ($row->installment !== null && $account->isCreditCard()) {
                $match = $this->matchInstallment($installmentPool, $usedIds, $row);

                if ($match !== null) {
                    $usedIds[$match->id] = true;
                    $decisions[] = new RowDecision($row, RowOutcome::ReplaceInstallment, $match->id);

                    continue;
                }
            }

            $adopted = $this->matchAdoption($adoptionPool, $usedIds, $row);

            if ($adopted !== null) {
                $usedIds[$adopted->id] = true;
                $decisions[] = new RowDecision($row, RowOutcome::Adopt, $adopted->id);

                continue;
            }

            $swapped = $this->matchSwap($swapPool, $usedIds, $row);

            if ($swapped !== null) {
                $usedIds[$swapped->id] = true;
                $decisions[] = new RowDecision($row, RowOutcome::SwapPending, $swapped->id);

                continue;
            }

            $decisions[] = new RowDecision($row, RowOutcome::New);
        }

        return $decisions;
    }

    /**
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     */
    private function matchInstallment(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id])) {
                continue;
            }

            $plan = $candidate->installmentPlan;

            if ($plan === null || $plan->installments !== $installment['total']) {
                continue;
            }

            if ($candidate->installment_number !== $installment['number']) {
                continue;
            }

            if (abs($candidate->amount->cents - $row->amount) > 1) {
                continue;
            }

            if (TokenSimilarity::overlap($plan->description, $row->description) < 0.6) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Candidata sem external_id, mesma direção/valor, data a −5/+3 dias da
     * linha e descrição parecida. Empate: data mais próxima, depois maior
     * similaridade, depois menor id.
     *
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     */
    private function matchAdoption(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
    {
        $rowDate = CarbonImmutable::parse($row->date)->startOfDay();
        $best = null;
        $bestRank = null;

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id])) {
                continue;
            }

            if ($candidate->direction !== $row->direction || $candidate->amount->cents !== $row->amount) {
                continue;
            }

            $diffDays = self::dateDiffInDays($candidate->date, $rowDate);

            if ($diffDays < -5 || $diffDays > 3) {
                continue;
            }

            $similarity = $this->descriptionSimilarity($candidate, $row);

            if ($similarity < 0.6) {
                continue;
            }

            $rank = [abs($diffDays), -$similarity, $candidate->id];

            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Candidata pending com external_id diferente, mesma data/valor/direção
     * e descrição bem parecida (≥ 0.7): troca de id ao virar posted.
     *
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     */
    private function matchSwap(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
    {
        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id]) || $candidate->external_id === $row->externalId) {
                continue;
            }

            if ($candidate->direction !== $row->direction || $candidate->amount->cents !== $row->amount) {
                continue;
            }

            if ($candidate->date->toDateString() !== $row->date) {
                continue;
            }

            if ($this->descriptionSimilarity($candidate, $row) < 0.7) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function descriptionSimilarity(Transaction $candidate, ParsedRow $row): float
    {
        return max(
            TokenSimilarity::overlap($candidate->description, $row->description),
            TokenSimilarity::overlap((string) $candidate->original_description, $row->description),
        );
    }

    private static function dateDiffInDays(CarbonImmutable $existing, CarbonImmutable $row): int
    {
        return (int) round(($existing->startOfDay()->getTimestamp() - $row->getTimestamp()) / 86400);
    }
}
