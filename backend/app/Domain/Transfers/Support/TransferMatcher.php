<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transfers\Data\TransferCandidate;
use App\Domain\Transfers\Data\TransferPair;
use Carbon\CarbonImmutable;

/**
 * Puro, sem banco: pontua pares de transações candidatas a transferência e
 * decide quais ligar automaticamente (match mútuo único) e quais sugerir.
 */
final class TransferMatcher
{
    private const KEYWORDS = ['TRANSF', 'PIX', 'TED', 'DOC', 'PAGAMENTO', 'FATURA', 'APLICACAO', 'RESGATE'];

    private const SUGGESTION_THRESHOLD = 0.3;

    /**
     * @param  list<TransferCandidate>  $candidates
     * @param  array<string, true>  $dismissed  chaves "out:in" descartadas
     * @return array{links: list<TransferPair>, suggestions: list<TransferPair>}
     */
    public static function match(array $candidates, array $dismissed = [], int $maxDays = 2): array
    {
        $outs = self::sortedById(array_values(array_filter(
            $candidates,
            fn (TransferCandidate $c) => $c->direction === Direction::Out,
        )));
        $ins = self::sortedById(array_values(array_filter(
            $candidates,
            fn (TransferCandidate $c) => $c->direction === Direction::In,
        )));

        /** @var list<array{out: int, in: int, score: float}> $pairs */
        $pairs = [];
        foreach ($outs as $out) {
            foreach ($ins as $in) {
                if (! self::isPossiblePair($out, $in, $maxDays, $dismissed)) {
                    continue;
                }

                $pairs[] = ['out' => $out->id, 'in' => $in->id, 'score' => self::score($out, $in)];
            }
        }

        $bestForOut = self::bestPartners($pairs, 'out', 'in');
        $bestForIn = self::bestPartners($pairs, 'in', 'out');

        $linkedOutIds = [];
        $linkedInIds = [];
        $links = [];

        foreach ($pairs as $pair) {
            $preferredByOut = $bestForOut[$pair['out']] ?? null;
            $preferredByIn = $bestForIn[$pair['in']] ?? null;

            if ($preferredByOut === $pair['in'] && $preferredByIn === $pair['out']) {
                $links[] = new TransferPair($pair['out'], $pair['in'], $pair['score']);
                $linkedOutIds[$pair['out']] = true;
                $linkedInIds[$pair['in']] = true;
            }
        }

        $suggestions = [];
        foreach ($pairs as $pair) {
            if ($pair['score'] < self::SUGGESTION_THRESHOLD) {
                continue;
            }

            if (isset($linkedOutIds[$pair['out']]) || isset($linkedInIds[$pair['in']])) {
                continue;
            }

            $suggestions[] = new TransferPair($pair['out'], $pair['in'], $pair['score']);
        }

        usort($links, fn (TransferPair $a, TransferPair $b) => [$a->outId, $a->inId] <=> [$b->outId, $b->inId]);
        usort($suggestions, fn (TransferPair $a, TransferPair $b) => [$a->outId, $a->inId] <=> [$b->outId, $b->inId]);

        return ['links' => $links, 'suggestions' => $suggestions];
    }

    public static function score(TransferCandidate $out, TransferCandidate $in): float
    {
        $days = self::dayDiff($out, $in);

        $score = match ($days) {
            0 => 0.6,
            1 => 0.45,
            2 => 0.3,
            default => 0.0,
        };

        if (self::hasDescriptionClue($out, $in)) {
            $score += 0.2;
        }

        if ($in->creditCard) {
            $score += 0.2;
        }

        return min($score, 1.0);
    }

    private static function hasDescriptionClue(TransferCandidate $out, TransferCandidate $in): bool
    {
        foreach ([$out->description, $in->description] as $description) {
            foreach (self::KEYWORDS as $keyword) {
                if (str_contains($description, $keyword)) {
                    return true;
                }
            }
        }

        if ($in->accountName !== '' && str_contains($out->description, $in->accountName)) {
            return true;
        }

        if ($out->accountName !== '' && str_contains($in->description, $out->accountName)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, true>  $dismissed
     */
    private static function isPossiblePair(TransferCandidate $out, TransferCandidate $in, int $maxDays, array $dismissed): bool
    {
        if ($out->amount !== $in->amount) {
            return false;
        }

        if ($out->accountId === $in->accountId) {
            return false;
        }

        if ($out->currency !== $in->currency) {
            return false;
        }

        if (self::dayDiff($out, $in) > $maxDays) {
            return false;
        }

        if (isset($dismissed["{$out->id}:{$in->id}"])) {
            return false;
        }

        return true;
    }

    /**
     * diffInDays devolve float (Carbon 3); as datas aqui não têm hora, então
     * o resultado é sempre um número inteiro de dias — convertido para
     * int para caber no match() estrito de score() por valor exato.
     */
    private static function dayDiff(TransferCandidate $out, TransferCandidate $in): int
    {
        return (int) abs(CarbonImmutable::parse($out->date)->diffInDays(CarbonImmutable::parse($in->date)));
    }

    /**
     * Para cada valor da chave $key, acha o $otherKey com maior pontuação —
     * só quando é único (empate no topo não entra, fica ambíguo).
     *
     * @param  list<array{out: int, in: int, score: float}>  $pairs
     * @return array<int, int>
     */
    private static function bestPartners(array $pairs, string $key, string $otherKey): array
    {
        /** @var array<int, list<array{out: int, in: int, score: float}>> $grouped */
        $grouped = [];
        foreach ($pairs as $pair) {
            $grouped[$pair[$key]][] = $pair;
        }

        $best = [];
        foreach ($grouped as $id => $group) {
            $maxScore = max(array_column($group, 'score'));
            $top = array_values(array_filter($group, fn (array $p) => abs($p['score'] - $maxScore) < 1e-9));

            if (count($top) === 1) {
                $best[$id] = $top[0][$otherKey];
            }
        }

        return $best;
    }

    /**
     * @param  list<TransferCandidate>  $candidates
     * @return list<TransferCandidate>
     */
    private static function sortedById(array $candidates): array
    {
        usort($candidates, fn (TransferCandidate $a, TransferCandidate $b) => $a->id <=> $b->id);

        return $candidates;
    }
}
