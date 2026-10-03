<?php

namespace App\Domain\Imports\Support;

/**
 * Lê um conteúdo CSV linha a linha (sem suporte a campo entre aspas com
 * quebra de linha): ignora linhas em branco e corta espaços de cada célula.
 * Sem escape por barra invertida (desligado explicitamente): um "\" dentro
 * de uma célula é um caractere comum, não um escape do delimitador ou das
 * aspas, que é como os bancos de fato exportam esses arquivos.
 */
final class CsvReader
{
    /**
     * @return list<array{line: int, cells: list<string>}>
     */
    public static function rows(string $content, string $delimiter): array
    {
        $rows = [];

        foreach (explode("\n", $content) as $index => $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }

            $cells = str_getcsv($rawLine, $delimiter, '"', '');
            $rows[] = [
                'line' => $index + 1,
                'cells' => array_map(static fn (?string $cell): string => trim($cell ?? ''), $cells),
            ];
        }

        return $rows;
    }
}
