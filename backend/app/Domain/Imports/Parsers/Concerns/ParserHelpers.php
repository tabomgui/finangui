<?php

namespace App\Domain\Imports\Parsers\Concerns;

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Support\CsvReader;
use App\Domain\Imports\Support\SyntheticId;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * Pedaços repetidos entre os parsers CSV: ligar cada linha lida pelo
 * CsvReader (que descarta linhas em branco) ao número real da linha no
 * arquivo, validar data no formato brasileiro, contar ocorrências de uma
 * mesma combinação para o id sintético, e decidir entre o id do próprio
 * arquivo e o sintético.
 */
trait ParserHelpers
{
    /**
     * @return list<array{line: int, cells: list<string>}>
     */
    private function csvRowsWithLineNumbers(string $content, string $delimiter): array
    {
        $lineNumbers = [];
        foreach (explode("\n", $content) as $index => $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }

            $lineNumbers[] = $index + 1;
        }

        $result = [];
        foreach (CsvReader::rows($content, $delimiter) as $i => $cells) {
            $result[] = ['line' => $lineNumbers[$i], 'cells' => $cells];
        }

        return $result;
    }

    private static function parseBrDate(string $raw): ?string
    {
        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($raw), $m)) {
            return null;
        }

        [, $day, $month, $year] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function nextOccurrence(array &$counts, string $key): int
    {
        $occurrence = $counts[$key] ?? 0;
        $counts[$key] = $occurrence + 1;

        return $occurrence;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function resolveExternalId(
        ?string $given,
        array &$counts,
        ImportFormat $format,
        string $date,
        int $amount,
        Direction $direction,
        string $description,
    ): string {
        if ($given !== null && $given !== '') {
            return $given;
        }

        $key = implode('|', [$date, (string) $amount, $direction->value, TextNormalizer::key($description)]);
        $occurrence = $this->nextOccurrence($counts, $key);

        return SyntheticId::for($format, $date, $amount, $direction, $description, $occurrence);
    }
}
