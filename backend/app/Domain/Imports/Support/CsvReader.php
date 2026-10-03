<?php

namespace App\Domain\Imports\Support;

/**
 * Lê um conteúdo CSV linha a linha (sem suporte a campo entre aspas com
 * quebra de linha): ignora linhas em branco e corta espaços de cada célula.
 */
final class CsvReader
{
    /**
     * @return list<list<string>>
     */
    public static function rows(string $content, string $delimiter): array
    {
        $rows = [];

        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line, $delimiter, '"');
            $rows[] = array_map(static fn (?string $cell): string => trim($cell ?? ''), $cells);
        }

        return $rows;
    }
}
