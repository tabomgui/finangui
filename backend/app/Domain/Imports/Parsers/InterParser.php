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
 * Extrato de conta corrente do Inter: preâmbulo livre antes do cabeçalho,
 * separador ";", valores no formato brasileiro, sem id próprio.
 */
final class InterParser implements Parser
{
    use ParserHelpers;

    /** @var list<string> */
    private const HEADER_CELLS = ['Data Lançamento', 'Histórico', 'Descrição', 'Valor', 'Saldo'];

    public function format(): ImportFormat
    {
        return ImportFormat::Inter;
    }

    public function accepts(string $content): bool
    {
        return $this->hasHeaderRow(CsvReader::rows($content, ';'), self::HEADER_CELLS);
    }

    public function parse(string $content, bool $creditCard): ParseResult
    {
        $rows = [];
        $failed = [];
        $counts = [];
        $usedGivenIds = [];
        $headerFound = false;

        foreach (CsvReader::rows($content, ';') as $entry) {
            $line = $entry['line'];
            $cells = $entry['cells'];

            if (! $headerFound) {
                if ($this->cellsMatchHeader($cells, self::HEADER_CELLS)) {
                    $headerFound = true;
                }

                continue;
            }

            $historico = trim($cells[1] ?? '');

            if ($this->isBalanceOrTotalRow($historico)) {
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

            $cents = BrazilianNumber::toCents($cells[3] ?? '');
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

            $descricao = trim($cells[2] ?? '');
            $description = self::joinDescriptionParts([$historico, $descricao]);

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
}
