<?php

namespace App\Domain\Imports\Support;

/**
 * Normaliza o conteúdo bruto de um arquivo importado antes do parsing:
 * detecta UTF-16 (LE/BE) pelo BOM e converte, remove o BOM UTF-8, converte
 * de Windows-1252 quando o conteúdo não é UTF-8 válido, e uniformiza
 * quebras de linha para "\n".
 */
final class Content
{
    public static function normalize(string $raw): string
    {
        if (str_starts_with($raw, "\xFF\xFE")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        } else {
            if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                $raw = substr($raw, 3);
            }

            if (! mb_check_encoding($raw, 'UTF-8')) {
                $raw = self::convertFromWindows1252($raw);
            }
        }

        $raw = str_replace("\r\n", "\n", $raw);

        return str_replace("\r", "\n", $raw);
    }

    /**
     * A causa normal de conteúdo não-UTF-8 é o arquivo inteiro estar em
     * Windows-1252. Mas alguns exports misturam linhas já em UTF-8 com
     * outras ainda em Windows-1252 (cópia e cola entre ferramentas
     * diferentes); converter o arquivo inteiro de uma vez corromperia as
     * linhas que já eram válidas. Por isso, quando a maioria das linhas já
     * é UTF-8 válida, só as linhas realmente inválidas são convertidas.
     */
    private static function convertFromWindows1252(string $raw): string
    {
        $lines = explode("\n", $raw);
        $invalidCount = 0;

        foreach ($lines as $line) {
            if (! mb_check_encoding($line, 'UTF-8')) {
                $invalidCount++;
            }
        }

        if ($invalidCount / count($lines) > 0.5) {
            return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        foreach ($lines as $i => $line) {
            if (! mb_check_encoding($line, 'UTF-8')) {
                $lines[$i] = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
            }
        }

        return implode("\n", $lines);
    }
}
