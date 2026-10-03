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
 * Extrato de conta corrente do C6: preâmbulo livre antes do cabeçalho,
 * separador "," com aspas (vírgula dentro de uma célula), colunas
 * separadas de entrada/saída em vez de um valor com sinal, sem id próprio.
 */
final class C6Parser implements Parser
{
    use ParserHelpers;

    /** @var list<string> */
    private const HEADER_CELLS = [
        'Data Lançamento', 'Data Contábil', 'Título', 'Descrição', 'Entrada(R$)', 'Saída(R$)', 'Saldo do Dia(R$)',
    ];

    public function format(): ImportFormat
    {
        return ImportFormat::C6;
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

            $titulo = trim($cells[2] ?? '');

            if ($this->isBalanceOrTotalRow($titulo)) {
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

            $entradaRaw = trim($cells[4] ?? '');
            $saidaRaw = trim($cells[5] ?? '');
            $entradaCents = $entradaRaw === '' ? 0 : BrazilianNumber::toCents($entradaRaw);
            $saidaCents = $saidaRaw === '' ? 0 : BrazilianNumber::toCents($saidaRaw);

            if ($entradaCents === null || $saidaCents === null) {
                $failed[] = ['line' => $line, 'reason' => 'Valor inválido.'];

                continue;
            }

            $entrada = abs($entradaCents);
            $saida = abs($saidaCents);

            if ($entrada > 0 && $saida > 0) {
                $failed[] = ['line' => $line, 'reason' => 'Valor inválido.'];

                continue;
            }

            if ($entrada === 0 && $saida === 0) {
                $failed[] = ['line' => $line, 'reason' => 'Valor zerado.'];

                continue;
            }

            if ($entrada > 0) {
                $direction = Direction::In;
                $amount = $entrada;
            } else {
                $direction = Direction::Out;
                $amount = $saida;
            }

            $descricaoCell = trim($cells[3] ?? '');
            $descricaoForJoin = mb_strtolower($titulo) === mb_strtolower($descricaoCell) ? null : $descricaoCell;
            $description = self::joinDescriptionParts([$titulo, $descricaoForJoin]);

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
