<?php

namespace App\Domain\Imports\Support;

/**
 * Normaliza o conteúdo bruto de um arquivo importado antes do parsing:
 * remove o BOM UTF-8, converte de Windows-1252 quando o conteúdo não é
 * UTF-8 válido, e uniformiza quebras de linha para "\n".
 */
final class Content
{
    public static function normalize(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $raw = str_replace("\r\n", "\n", $raw);

        return str_replace("\r", "\n", $raw);
    }
}
