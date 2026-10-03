<?php

namespace App\Domain\Imports\Parsers\Concerns;

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Support\SyntheticId;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * Pedaços repetidos entre os parsers: casar o cabeçalho ignorando
 * acento/caixa, reconhecer uma linha de saldo/total pra pular sem contar
 * como falha, validar data no formato brasileiro, contar ocorrências de
 * uma mesma combinação para o id sintético, e decidir entre o id do
 * próprio arquivo e o sintético — inclusive quando o id do arquivo se
 * repete (a repetição não pode ganhar o id de outra linha).
 */
trait ParserHelpers
{
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    private const MAX_EXTERNAL_ID_LENGTH = 255;

    /**
     * @param  list<string>  $expectedCells
     * @param  list<string>  $cells
     */
    private function cellsMatchHeader(array $cells, array $expectedCells): bool
    {
        if (count($cells) < count($expectedCells)) {
            return false;
        }

        foreach ($expectedCells as $i => $expected) {
            if (TextNormalizer::normalize($cells[$i] ?? '') !== TextNormalizer::normalize($expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{line: int, cells: list<string>}>  $rows
     * @param  list<string>  $expectedCells
     */
    private function hasHeaderRow(array $rows, array $expectedCells): bool
    {
        foreach ($rows as $entry) {
            if ($this->cellsMatchHeader($entry['cells'], $expectedCells)) {
                return true;
            }
        }

        return false;
    }

    /** Linha de saldo/total do extrato (ex.: "Saldo do dia", "Total do período"): nunca é um lançamento. */
    private function isBalanceOrTotalRow(string $text): bool
    {
        $normalized = TextNormalizer::normalize($text);

        return str_starts_with($normalized, 'SALDO') || str_starts_with($normalized, 'TOTAL');
    }

    private static function yearInBounds(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    private static function parseBrDate(string $raw): ?string
    {
        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($raw), $m)) {
            return null;
        }

        [, $day, $month, $year] = $m;

        if (! self::yearInBounds((int) $year) || ! checkdate((int) $month, (int) $day, (int) $year)) {
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

    /** Um id vindo do próprio arquivo pode, em tese, ser arbitrariamente longo. */
    private static function capExternalId(string $id): string
    {
        return mb_strlen($id) > self::MAX_EXTERNAL_ID_LENGTH ? 'h:'.sha1($id) : $id;
    }

    /**
     * @param  array<string, true>  $usedGivenIds  ids do próprio arquivo já usados nesse parse, por valor
     * @param  array<string, int>  $counts
     */
    private function resolveExternalId(
        ?string $given,
        array &$usedGivenIds,
        array &$counts,
        ImportFormat $format,
        string $date,
        int $amount,
        Direction $direction,
        string $description,
    ): string {
        if ($given !== null && $given !== '' && ! isset($usedGivenIds[$given])) {
            $usedGivenIds[$given] = true;

            return self::capExternalId($given);
        }

        $key = implode('|', [$date, (string) $amount, $direction->value, TextNormalizer::key($description)]);
        $occurrence = $this->nextOccurrence($counts, $key);

        return SyntheticId::for($format, $date, $amount, $direction, $description, $occurrence);
    }

    /**
     * @param  list<?string>  $parts
     */
    private static function joinDescriptionParts(array $parts): string
    {
        $parts = array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '');

        return implode(' - ', $parts);
    }
}
