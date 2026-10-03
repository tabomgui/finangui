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
 * Extrato de conta corrente do Nubank: sem preâmbulo, separador ",",
 * valores com ponto decimal, id próprio na coluna "Identificador" (nem
 * sempre presente).
 */
final class NubankParser implements Parser
{
    use ParserHelpers;

    private const HEADER = 'Data,Valor,Identificador,Descrição';

    public function format(): ImportFormat
    {
        return ImportFormat::Nubank;
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

            $date = self::parseBrDate($dateRaw);
            if ($date === null) {
                $failed[] = ['line' => $line, 'reason' => 'Data inválida.'];

                continue;
            }

            $cents = BrazilianNumber::toCents($cells[1] ?? '');
            if ($cents === null) {
                $failed[] = ['line' => $line, 'reason' => 'Valor inválido.'];

                continue;
            }

            if ($cents === 0) {
                $failed[] = ['line' => $line, 'reason' => 'Valor zerado.'];

                continue;
            }

            $direction = $cents < 0 ? Direction::Out : Direction::In;
            $amount = abs($cents);
            $description = trim($cells[3] ?? '');

            $installment = null;
            if ($creditCard) {
                [$description, $installment] = InstallmentSuffix::extract($description);
            }

            $identifier = trim($cells[2] ?? '');
            $externalId = $this->resolveExternalId(
                $identifier === '' ? null : $identifier,
                $counts,
                $this->format(),
                $date,
                $amount,
                $direction,
                $description,
            );

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
        return mb_strtolower(trim($cells[0] ?? '')) === 'data' && count($cells) >= 4;
    }
}
