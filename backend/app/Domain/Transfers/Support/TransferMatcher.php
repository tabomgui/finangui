<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transfers\Data\TransferCandidate;
use App\Domain\Transfers\Data\TransferPair;
use Carbon\CarbonImmutable;

/**
 * Puro, sem banco: pontua pares de transações candidatas a transferência e
 * decide quais ligar automaticamente (match mútuo único, com evidência e
 * sem nenhum dos impedimentos de canAutoLink()) e quais sugerir (pontuação
 * mínima, no máximo duas por transação).
 */
final class TransferMatcher
{
    private const SUGGESTION_THRESHOLD = 0.45;

    private const MAX_SUGGESTIONS_PER_TRANSACTION = 2;

    /**
     * @param  list<TransferCandidate>  $candidates
     * @param  array<string, true>  $dismissed  chaves "out:in" descartadas
     * @return array{links: list<TransferPair>, suggestions: list<TransferPair>}
     */
    public static function match(array $candidates, array $dismissed = [], int $maxDays = 2): array
    {
        /** @var array<int, TransferCandidate> $byId */
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->id] = $candidate;
        }

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

        $links = [];
        /** @var array<string, true> $linkedPairKeys */
        $linkedPairKeys = [];

        foreach ($pairs as $pair) {
            $preferredByOut = $bestForOut[$pair['out']] ?? null;
            $preferredByIn = $bestForIn[$pair['in']] ?? null;

            if ($preferredByOut !== $pair['in'] || $preferredByIn !== $pair['out']) {
                continue;
            }

            if (! self::canAutoLink($byId[$pair['out']], $byId[$pair['in']])) {
                // Match mútuo único, mas sem evidência/com algum impedimento
                // (cartão, pendente, categoria manual): não liga sozinho,
                // mas ainda compete normalmente por uma vaga de sugestão
                // abaixo (ver buildSuggestions()) — só a chave exata do par
                // fica marcada para não entrar duas vezes.
                continue;
            }

            $links[] = new TransferPair($pair['out'], $pair['in'], $pair['score']);
            $linkedPairKeys["{$pair['out']}:{$pair['in']}"] = true;
        }

        $suggestions = self::buildSuggestions($pairs, $linkedPairKeys);

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

        if (TransferClues::hasClue($out, $in)) {
            $score += 0.2;
        }

        if ($in->creditCard) {
            $score += 0.2;
        }

        return min($score, 1.0);
    }

    /**
     * Impedimentos à ligação automática de um match mútuo único — todos
     * esses casos ainda podem virar sugestão, só não ligam sozinhos:
     * nenhuma perna pendente; nenhuma perna com categoria manual numa
     * categoria que não é de transferência (o usuário já decidiu o que
     * aquele lançamento é); a saída nunca é de cartão de crédito; e, se a
     * entrada é de cartão, só liga com pista de pagamento de fatura
     * (`PAGAMENTO`/`FATURA`) e sem `ESTORNO`. Em qualquer caso, exige
     * evidência (a mesma pista que soma a pontuação em score()) — um par
     * só por coincidência de valor e data nunca liga sozinho.
     */
    private static function canAutoLink(TransferCandidate $out, TransferCandidate $in): bool
    {
        if ($out->pending || $in->pending) {
            return false;
        }

        if ($out->manualNonTransferCategory || $in->manualNonTransferCategory) {
            return false;
        }

        if ($out->creditCard) {
            return false;
        }

        if (! TransferClues::hasClue($out, $in)) {
            return false;
        }

        if ($in->creditCard) {
            if (! TransferClues::hasPaymentClue($out, $in)) {
                return false;
            }

            if (TransferClues::hasEstornoClue($out, $in)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{out: int, in: int, score: float}>  $pairs
     * @param  array<string, true>  $linkedPairKeys  chaves "out:in" que já ligaram de verdade (ver match()) — não voltam a aparecer como sugestão
     * @return list<TransferPair>
     */
    private static function buildSuggestions(array $pairs, array $linkedPairKeys): array
    {
        $eligible = [];
        foreach ($pairs as $pair) {
            if ($pair['score'] < self::SUGGESTION_THRESHOLD) {
                continue;
            }

            if (isset($linkedPairKeys["{$pair['out']}:{$pair['in']}"])) {
                continue;
            }

            $eligible[] = $pair;
        }

        // Maior pontuação primeiro (empate: ids menores primeiro, só para
        // desempate determinístico): o teto de duas sugestões por
        // transação, abaixo, favorece sempre a maior pontuação quando uma
        // transação tem mais de dois pares candidatos.
        usort($eligible, function (array $a, array $b): int {
            $byScore = $b['score'] <=> $a['score'];

            return $byScore !== 0 ? $byScore : [$a['out'], $a['in']] <=> [$b['out'], $b['in']];
        });

        /** @var array<int, int> $outCount */
        $outCount = [];
        /** @var array<int, int> $inCount */
        $inCount = [];
        $suggestions = [];

        foreach ($eligible as $pair) {
            $outId = $pair['out'];
            $inId = $pair['in'];

            if (($outCount[$outId] ?? 0) >= self::MAX_SUGGESTIONS_PER_TRANSACTION) {
                continue;
            }

            if (($inCount[$inId] ?? 0) >= self::MAX_SUGGESTIONS_PER_TRANSACTION) {
                continue;
            }

            $suggestions[] = new TransferPair($outId, $inId, $pair['score']);
            $outCount[$outId] = ($outCount[$outId] ?? 0) + 1;
            $inCount[$inId] = ($inCount[$inId] ?? 0) + 1;
        }

        return $suggestions;
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
     * só quando é único (empate no topo não entra, fica ambígua).
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
