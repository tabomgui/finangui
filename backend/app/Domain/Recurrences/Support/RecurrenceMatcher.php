<?php

namespace App\Domain\Recurrences\Support;

use App\Domain\Imports\Support\TokenSimilarity;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Casa um lançamento real (linha importada ou lançamento manual) com uma
 * ocorrência prevista de recorrência ainda não confirmada (status
 * projected, recurrence_id preenchido): mesma direção (a conta já vem
 * filtrada no pool recebido), valor dentro de ±10% do previsto,
 * data_prevista − data_real entre −5 e +3 dias (mesma janela de
 * App\Domain\Imports\Support\DedupMatchers::matchAdoption(), "como a
 * adoção") e descrição: quando a recorrência tem match_pattern, a
 * descrição real normalizada precisa contê-lo; senão, sobreposição de
 * tokens ≥ 0.6 com a descrição da prevista. Empate: data mais próxima,
 * depois valor mais próximo, depois menor id.
 *
 * Puro em relação ao banco — só compara os models que o chamador já
 * carregou (a prevista precisa vir com a relação `recurrence` carregada,
 * para o match_pattern, sem N+1 — ver
 * App\Domain\Imports\Support\IngestionPlanner::recurrencePool() e
 * App\Domain\Transactions\Actions\CreateTransaction).
 */
final class RecurrenceMatcher
{
    /**
     * @param  Collection<int, Transaction>  $pool  previstas candidatas, já filtradas por conta
     * @param  array<int, true>  $usedIds  previstas já casadas noutra linha do mesmo lote, para nunca usar a mesma prevista duas vezes
     */
    public function bestMatch(Collection $pool, array $usedIds, int $amount, Direction $direction, string $date, string $description): ?Transaction
    {
        $realDate = CarbonImmutable::parse($date)->startOfDay();
        $best = null;
        $bestRank = null;

        foreach ($pool as $candidate) {
            if (isset($usedIds[$candidate->id]) || $candidate->direction !== $direction) {
                continue;
            }

            if (! self::withinTolerance($candidate->amount->cents, $amount)) {
                continue;
            }

            $diffDays = self::dateDiffInDays($candidate->recurrence_date, $realDate);

            if ($diffDays < -5 || $diffDays > 3) {
                continue;
            }

            if (! self::descriptionMatches($candidate, $description)) {
                continue;
            }

            $rank = [abs($diffDays), abs($candidate->amount->cents - $amount), $candidate->id];

            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * ±10% do valor previsto (o da prevista, nunca do valor real que
     * chegou): compara os dois lados ×10 para a borda exata de 10% nunca
     * cair fora por arredondamento de ponto flutuante.
     */
    private static function withinTolerance(int $projected, int $real): bool
    {
        return abs($real - $projected) * 10 <= $projected;
    }

    private static function descriptionMatches(Transaction $candidate, string $description): bool
    {
        $pattern = $candidate->recurrence?->match_pattern;

        if ($pattern !== null && $pattern !== '') {
            return str_contains(TextNormalizer::key($description), TextNormalizer::key($pattern));
        }

        return TokenSimilarity::overlap($candidate->description, $description) >= 0.6;
    }

    /**
     * Dias inteiros entre as duas datas (sem hora): positivo quando a
     * prevista é depois do lançamento real, negativo quando é antes.
     */
    private static function dateDiffInDays(CarbonImmutable $projected, CarbonImmutable $real): int
    {
        return (int) $real->startOfDay()->diffInDays($projected->startOfDay(), false);
    }
}
