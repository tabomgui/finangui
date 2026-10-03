<?php

namespace App\Domain\Imports\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decide o destino de cada linha sem gravar nada: duplicada/atualizada por
 * external_id, substituição de parcela projetada ou já lançada, adoção de
 * lançamento manual e troca de id de uma pendente — na ordem em que a
 * primeira regra que casar vence. Usado tanto pela prévia quanto pela
 * confirmação, para garantir que as duas vejam os mesmos números. Consulta
 * só a conta de destino e janelas de datas em volta das linhas do arquivo.
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
        $minDate = CarbonImmutable::parse(min($dates));
        $maxDate = CarbonImmutable::parse(max($dates));
        $externalIds = array_values(array_unique(array_map(fn (ParsedRow $row) => $row->externalId, $rows)));
        $externalIdsInFile = array_fill_keys($externalIds, true);

        $windowed = Transaction::query()
            ->where('account_id', $account->id)
            ->where(function (Builder $query) use ($minDate, $maxDate, $externalIds) {
                $query->whereBetween('date', [$minDate->subDays(7)->toDateString(), $maxDate->addDays(7)->toDateString()])
                    ->orWhereIn('external_id', $externalIds);
            })
            ->get();

        // Parcela de plano do cartão sem external_id, em qualquer status: a
        // parcela 1 de uma compra manual nasce lançada e o job diário lança
        // as demais, então tanto posted quanto projected podem ser a mesma
        // parcela que o banco está relatando agora.
        $installmentCandidates = Transaction::query()
            ->where('account_id', $account->id)
            ->whereNull('external_id')
            ->whereNotNull('installment_plan_id')
            ->where(function (Builder $query) use ($minDate, $maxDate) {
                $query->whereBetween('date', [$minDate->subDays(35)->toDateString(), $maxDate->addDays(35)->toDateString()])
                    ->orWhereHas('installmentPlan', fn (Builder $plan) => $plan->whereBetween(
                        'purchase_date',
                        [$minDate->subDays(5)->toDateString(), $maxDate->addDays(5)->toDateString()],
                    ));
            })
            ->get();

        $candidates = $windowed->concat($installmentCandidates)->unique('id')->values();
        // Garante installmentPlan carregado em todos (quem só veio de $windowed
        // não tinha a relação), sem N+1: uma única query extra para o lote.
        $candidates->load('installmentPlan');

        $byExternalId = $candidates->whereNotNull('external_id')->keyBy('external_id');
        $installmentPool = $candidates->whereNull('external_id')->whereNotNull('installment_plan_id')->values();
        // Parcelas não são lançamentos manuais livres: têm seu próprio
        // casamento (replace_installment) e não devem ser "adotadas" por
        // engano só por também não terem external_id.
        $adoptionPool = $candidates->whereNull('external_id')->whereNull('installment_plan_id')->values();
        $swapPool = $candidates->filter(
            fn (Transaction $t) => $t->external_id !== null && $t->status === TransactionStatus::Pending
        )->values();

        $decisions = [];
        $usedIds = [];
        $seenExternalIds = [];
        // Parcelas novas (sem casamento no banco) da mesma compra, vistas
        // mais cedo neste mesmo arquivo: IngestTransactions cria o plano na
        // primeira (outcome New) e substitui a parcela projetada nas
        // seguintes (ver matchesPlanSeedInBatch) — espelhado aqui só para a
        // prévia mostrar o mesmo desfecho, sem gravar nada.
        $plansThisBatch = [];

        foreach ($rows as $row) {
            if (isset($seenExternalIds[$row->externalId])) {
                $decisions[] = new RowDecision($row, RowOutcome::Duplicate);

                continue;
            }
            $seenExternalIds[$row->externalId] = true;

            $existing = $byExternalId->get($row->externalId);

            if ($existing !== null) {
                $usedIds[$existing->id] = true;
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

            $swapped = $this->matchSwap($swapPool, $usedIds, $externalIdsInFile, $row);

            if ($swapped !== null) {
                $usedIds[$swapped->id] = true;
                $decisions[] = new RowDecision($row, RowOutcome::SwapPending, $swapped->id);

                continue;
            }

            if ($row->installment !== null && $account->isCreditCard()
                && $this->matchesPlanSeedInBatch($plansThisBatch, $row)) {
                $decisions[] = new RowDecision($row, RowOutcome::ReplaceInstallment);

                continue;
            }

            $decisions[] = new RowDecision($row, RowOutcome::New);

            if ($row->installment !== null && $account->isCreditCard()) {
                /** @var array{number: int, total: int} $installment */
                $installment = $row->installment;
                $plansThisBatch[] = [
                    'total' => $installment['total'],
                    'descriptionKey' => TextNormalizer::key($row->description),
                    'amount' => $row->amount,
                ];
            }
        }

        return $decisions;
    }

    /**
     * Mesma regra de IngestTransactions::matchPlanCreatedThisBatch(): total,
     * chave de descrição e valor com diferença menor que o total de
     * parcelas (o resto da divisão nunca passa disso). Só diz que a
     * execução vai substituir — qual parcela, e contra qual plano, só existe
     * de verdade na hora do IngestTransactions.
     *
     * @param  list<array{total: int, descriptionKey: string, amount: int}>  $seeds
     */
    private function matchesPlanSeedInBatch(array $seeds, ParsedRow $row): bool
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;
        $descriptionKey = TextNormalizer::key($row->description);

        foreach ($seeds as $seed) {
            if ($seed['total'] !== $installment['total'] || $seed['descriptionKey'] !== $descriptionKey) {
                continue;
            }

            if (abs($seed['amount'] - $row->amount) < $seed['total']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parcela de plano do mesmo cartão sem external_id, mesma direção,
     * installments do plano = total, installment_number = N, valor com
     * diferença menor que o número de parcelas do plano (o resto da divisão
     * pode cair na primeira ou na última parcela, e nunca passa do total de
     * parcelas) e descrição do plano parecida; data da parcela a até 35
     * dias da linha, ou data da compra do plano a até 5 dias (alguns bancos
     * datam a parcela com a data da compra). Empate: data mais próxima,
     * depois menor id.
     *
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     */
    private function matchInstallment(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;
        $rowDate = CarbonImmutable::parse($row->date)->startOfDay();
        $best = null;
        $bestRank = null;

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id]) || $candidate->direction !== $row->direction) {
                continue;
            }

            $plan = $candidate->installmentPlan;

            if ($plan === null || $plan->installments !== $installment['total']) {
                continue;
            }

            if ($candidate->installment_number !== $installment['number']) {
                continue;
            }

            if (abs($candidate->amount->cents - $row->amount) >= $plan->installments) {
                continue;
            }

            if (TokenSimilarity::overlap($plan->description, $row->description) < 0.6) {
                continue;
            }

            $parcelDiff = abs(self::dateDiffInDays($candidate->date, $rowDate));
            $purchaseDiff = abs(self::dateDiffInDays($plan->purchase_date, $rowDate));

            if ($parcelDiff > 35 && $purchaseDiff > 5) {
                continue;
            }

            $rank = [$parcelDiff, $candidate->id];

            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $candidate;
            }
        }

        return $best;
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
     * e descrição bem parecida (≥ 0.7): troca de id ao virar posted. Só para
     * linha posted; candidatas cujo external_id aparece em qualquer linha do
     * próprio arquivo ficam de fora (já estão reservadas para o casamento
     * exato por external_id de outra linha, em qualquer ordem).
     *
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     * @param  array<string, true>  $externalIdsInFile
     */
    private function matchSwap(Collection $pool, array $usedIds, array $externalIdsInFile, ParsedRow $row): ?Transaction
    {
        if ($row->pending) {
            return null;
        }

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id]) || isset($externalIdsInFile[$candidate->external_id])) {
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

    /**
     * Dias inteiros entre as duas datas (sem hora): positivo quando $existing
     * é depois de $row, negativo quando é antes.
     */
    private static function dateDiffInDays(CarbonImmutable $existing, CarbonImmutable $row): int
    {
        return (int) $row->startOfDay()->diffInDays($existing->startOfDay(), false);
    }
}
