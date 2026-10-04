<?php

namespace App\Domain\Rules\Support;

use Illuminate\Support\Str;

/**
 * Texto comparável: maiúsculas, sem acento, espaços colapsados. A chave
 * (key) também tira dígitos e pontuação, para agrupar descrições que só
 * variam em números (ex.: "UBER *TRIP 1234" e "UBER TRIP 98").
 */
final class TextNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $ascii = Str::ascii($text);

        return trim((string) preg_replace('/\s+/', ' ', mb_strtoupper($ascii)));
    }

    /**
     * Cabe na coluna description_key (varchar 255): a transliteração pode
     * expandir o texto (ex.: "Щ" vira "Shh"), então o corte é no resultado
     * final, não no texto de entrada.
     */
    public static function key(?string $text): string
    {
        $normalized = self::normalize($text);
        $lettersOnly = (string) preg_replace('/[^A-Z ]+/', ' ', $normalized);
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $lettersOnly));

        return mb_substr($collapsed, 0, 255);
    }
}
