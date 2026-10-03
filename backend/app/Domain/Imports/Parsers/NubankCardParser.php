<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\Concerns\ParserHelpers;
use App\Domain\Imports\Support\BrazilianNumber;
use App\Domain\Imports\Support\CsvReader;
use App\Domain\Imports\Support\InstallmentSuffix;
use App\Domain\Transactions\Enums\Direction;

/**
 * Fatura do cartão Nubank: sem preâmbulo, separador ",", data ISO, sem id
 * próprio. Convenção de sinal invertida em relação aos outros formatos:
 * valor positivo é compra (saída), negativo é pagamento/estorno (entrada).
 */
final class NubankCardParser implements Parser
{
    use ParserHelpers;

    /** @var list<string> */
    private const HEADER_CELLS = ['date', 'title', 'amount'];

    public function format(): ImportFormat
    {
        return ImportFormat::NubankCard;
    }

    public function accepts(string $content): bool
    {
        return $this->hasHeaderRow(CsvReader::rows($content, ','), self::HEADER_CELLS);
    }

    public function parse(string $content, bool $creditCard): ParseResult
    {
        $rows = [];
        $failed = [];
        $counts = [];
        $usedGivenIds = [];
        $headerFound = false;

        foreach (CsvReader::rows($content, ',') as $entry) {
            $line = $entry['line'];
            $cells = $entry['cells'];

            if (! $headerFound) {
                if ($this->cellsMatchHeader($cells, self::HEADER_CELLS)) {
                    $headerFound = true;
                }

                continue;
            }

            $title = trim($cells[1] ?? '');

            if ($this->isBalanceOrTotalRow($title)) {
                continue;
            }

            $dateRaw = $cells[0] ?? '';
            if (trim($dateRaw) === '') {
                continue;
            }

            $date = self::parseIsoDate($dateRaw);
            if ($date === null) {
                $failed[] = ['line' => $line, 'reason' => 'Data inválida.'];

                continue;
            }

            $cents = BrazilianNumber::toCents($cells[2] ?? '');
            if ($cents === null) {
                $failed[] = ['line' => $line, 'reason' => 'Valor inválido.'];

                continue;
            }

            if ($cents === 0) {
                $failed[] = ['line' => $line, 'reason' => 'Valor zerado.'];

                continue;
            }

            $direction = $cents > 0 ? Direction::Out : Direction::In;
            $amount = abs($cents);
            $description = $title;

            $installment = null;
            if ($creditCard && $direction === Direction::Out) {
                [$description, $installment] = InstallmentSuffix::extract($description);
            }

            $externalId = $this->resolveExternalId(null, $usedGivenIds, $counts, $this->format(), $date, $amount, $direction, $description);

            $rows[] = new ParsedRow(
                line: $line,
                date: $date,
                amount: $amount,
                direction: $direction,
                description: $description,
                externalId: $externalId,
                installment: $installment,
            );
        }

        if (! $headerFound) {
            $failed[] = ['line' => 1, 'reason' => 'Cabeçalho não encontrado.'];
        }

        return new ParseResult($rows, $failed);
    }

    private static function parseIsoDate(string $raw): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m)) {
            return null;
        }

        [, $year, $month, $day] = $m;

        if (! self::yearInBounds((int) $year) || ! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }
}
