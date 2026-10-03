<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * As comparações candidata-a-candidata da cascata de dedup do
 * IngestionPlanner: cada método varre um pool já filtrado e devolve a
 * melhor candidata (ou nenhuma). Puro em relação ao banco — só compara os
 * models que o IngestionPlanner já carregou.
 */
final class DedupMatchers
{
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
    public function matchInstallment(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
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
     * Pagamento de fatura (perna "in" de uma transferência, ex.:
     * PayStatement) que o próprio extrato do cartão também relata: mesma
     * direção/valor e data em janela — sem exigir semelhança de descrição
     * (a perna da transferência quase nunca descreve a mesma coisa com as
     * mesmas palavras que o banco). Empate: data mais próxima, depois menor
     * id. Só chamado em conta credit_card para linha de entrada.
     *
     * @param  Collection<int, Transaction>  $pool
     * @param  array<int, true>  $usedIds
     */
    public function matchCardPayment(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
    {
        $rowDate = CarbonImmutable::parse($row->date)->startOfDay();
        $best = null;
        $bestRank = null;

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id]) || $candidate->transfer_id === null) {
                continue;
            }

            if ($candidate->direction !== $row->direction || $candidate->amount->cents !== $row->amount) {
                continue;
            }

            $diffDays = self::dateDiffInDays($candidate->date, $rowDate);

            if ($diffDays < -5 || $diffDays > 3) {
                continue;
            }

            $rank = [abs($diffDays), $candidate->id];

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
    public function matchAdoption(Collection $pool, array $usedIds, ParsedRow $row): ?Transaction
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

            $similarity = self::descriptionSimilarity($candidate, $row);

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
    public function matchSwap(Collection $pool, array $usedIds, array $externalIdsInFile, ParsedRow $row): ?Transaction
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

            if (self::descriptionSimilarity($candidate, $row) < 0.7) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private static function descriptionSimilarity(Transaction $candidate, ParsedRow $row): float
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
