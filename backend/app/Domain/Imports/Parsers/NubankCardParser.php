<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\Concerns\ParserHelpers;
use App\Domain\Imports\Support\BrazilianNumber;
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

    private const HEADER = 'date,title,amount';

    public function format(): ImportFormat
    {
        return ImportFormat::NubankCard;
    }

    public function accepts(string $content): bool
    {
        return str_contains($content, self::HEADER);
    }

    public function parse(string $content, bool $creditCard): ParseResult
    {
        $rows = [];
        $failed = [];
        $counts = [];
        $headerFound = false;

        foreach ($this->csvRowsWithLineNumbers($content, ',') as $entry) {
            $line = $entry['line'];
            $cells = $entry['cells'];

            if (! $headerFound) {
                if ($this->isHeader($cells)) {
                    $headerFound = true;
                }

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
            $description = trim($cells[1] ?? '');

            $installment = null;
            if ($creditCard) {
                [$description, $installment] = InstallmentSuffix::extract($description);
            }

            $externalId = $this->resolveExternalId(null, $counts, $this->format(), $date, $amount, $direction, $description);

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

        return new ParseResult($rows, $failed);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isHeader(array $cells): bool
    {
        return mb_strtolower(trim($cells[0] ?? '')) === 'date' && count($cells) >= 3;
    }

    private static function parseIsoDate(string $raw): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m)) {
            return null;
        }

        [, $year, $month, $day] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }
}
