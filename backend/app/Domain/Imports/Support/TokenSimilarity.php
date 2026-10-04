<?php

namespace App\Domain\Imports\Support;

use App\Domain\Rules\Support\TextNormalizer;

/**
 * Sobreposição de tokens entre duas descrições, usada pela cascata de dedup
 * (adoção de lançamento manual, troca de pending→posted, parcela): quanto
 * mais perto de 1.0, mais provável que as duas descrições se refiram à
 * mesma coisa, mesmo com redações diferentes.
 */
final class TokenSimilarity
{
    /**
     * Tokens de TextNormalizer::key() com 3+ letras, como conjunto (sem
     * repetição). |A∩B| / min(|A|,|B|); se algum lado não tem token, 0.0.
     */
    public static function overlap(string $a, string $b): float
    {
        $tokensA = self::tokens($a);
        $tokensB = self::tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $intersection = array_intersect($tokensA, $tokensB);

        return count($intersection) / min(count($tokensA), count($tokensB));
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $key = TextNormalizer::key($text);

        if ($key === '') {
            return [];
        }

        $words = array_filter(explode(' ', $key), fn (string $word) => mb_strlen($word) >= 3);

        return array_values(array_unique($words));
    }
}
