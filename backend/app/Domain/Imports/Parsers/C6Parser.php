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
 * Extrato de conta corrente do C6: preâmbulo livre antes do cabeçalho,
 * separador "," com aspas (vírgula dentro de uma célula), colunas
 * separadas de entrada/saída em vez de um valor com sinal, sem id próprio.
 */
final class C6Parser implements Parser
{
    use ParserHelpers;

    private const HEADER = 'Data Lançamento,Data Contábil,Título,Descrição,Entrada(R$),Saída(R$),Saldo do Dia(R$)';

    public function format(): ImportFormat
    {
        return ImportFormat::C6;
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

            $entradaRaw = trim($cells[4] ?? '');
            $saidaRaw = trim($cells[5] ?? '');
            $entrada = $entradaRaw === '' ? 0 : BrazilianNumber::toCents($entradaRaw);
            $saida = $saidaRaw === '' ? 0 : BrazilianNumber::toCents($saidaRaw);

            if ($entrada === null || $saida === null) {
                $failed[] = ['line' => $line, 'reason' => 'Valor inválido.'];

                continue;
            }

            if ($entrada <= 0 && $saida <= 0) {
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

            $titulo = trim($cells[2] ?? '');
            $descricao = trim($cells[3] ?? '');
            $description = mb_strtolower($titulo) === mb_strtolower($descricao) || $descricao === ''
                ? $titulo
                : $titulo.' - '.$descricao;

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
        return mb_strtolower(trim($cells[0] ?? '')) === 'data lançamento' && count($cells) >= 7;
    }
}
