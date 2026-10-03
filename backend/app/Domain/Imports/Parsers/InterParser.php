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
 * Extrato de conta corrente do Inter: preâmbulo livre antes do cabeçalho,
 * separador ";", valores no formato brasileiro, sem id próprio.
 */
final class InterParser implements Parser
{
    use ParserHelpers;

    private const HEADER = 'Data Lançamento;Histórico;Descrição;Valor;Saldo';

    public function format(): ImportFormat
    {
        return ImportFormat::Inter;
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

        foreach ($this->csvRowsWithLineNumbers($content, ';') as $entry) {
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

            $historico = trim($cells[1] ?? '');
            $descricao = trim($cells[2] ?? '');
            $description = $descricao === '' ? $historico : $historico.' - '.$descricao;

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
        return mb_strtolower(trim($cells[0] ?? '')) === 'data lançamento' && count($cells) >= 5;
    }
}
