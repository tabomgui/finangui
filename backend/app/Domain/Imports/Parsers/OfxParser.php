<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\Concerns\ParserHelpers;
use App\Domain\Imports\Support\BrazilianNumber;
use App\Domain\Imports\Support\InstallmentSuffix;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * OFX genérico (SGML 1.x ou XML 2.x), conta ou cartão: blocos <STMTTRN>
 * lidos por regex (tolerante a tags sem fechamento e a espaços/quebras de
 * linha entre elas), sem depender de um parser XML (o SGML não é XML
 * válido). Um bloco termina no fechamento de </STMTTRN>, no início do
 * próximo <STMTTRN>, no fechamento da lista que os envolve
 * (</BANKTRANLIST>, </STMTRS> ou </CCSTMTRS>) ou no fim do conteúdo — um
 * arquivo truncado ainda assim lê o último bloco. TRNAMT negativo é
 * sempre saída. FITID é o id próprio; quando ausente (ou repetido dentro
 * do mesmo arquivo), usa o sintético.
 */
final class OfxParser implements Parser
{
    use ParserHelpers;

    private const BLOCK_PATTERN = '/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/(?:BANKTRANLIST|STMTRS|CCSTMTRS)>)|\z)/si';

    public function format(): ImportFormat
    {
        return ImportFormat::Ofx;
    }

    public function accepts(string $content): bool
    {
        if (stripos($content, '<STMTTRN') === false) {
            return false;
        }

        return stripos($content, 'OFXHEADER') !== false || stripos($content, '<OFX') !== false;
    }

    public function parse(string $content, bool $creditCard): ParseResult
    {
        $rows = [];
        $failed = [];
        $counts = [];
        $usedGivenIds = [];

        $matched = preg_match_all(self::BLOCK_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE);

        if ($matched === false) {
            return new ParseResult([], [['line' => 1, 'reason' => 'Não foi possível ler o arquivo OFX.']], unrecognized: true);
        }

        // Nenhum bloco de transação: ou o arquivo realmente não é OFX (ex.:
        // formato forçado por engano sobre um CSV), ou é um extrato OFX
        // estruturalmente válido só que vazio no período — indistinguíveis
        // aqui, então trata como "não reconhecido" (o caso comum e o único
        // em que isto importa: um extrato vazio de verdade não teria sido
        // enviado).
        if ($matched === 0) {
            return new ParseResult([], [], unrecognized: true);
        }

        $line = 1;
        $previousOffset = 0;

        foreach ($matches[1] as [$block, $offset]) {
            $line += substr_count($content, "\n", $previousOffset, $offset - $previousOffset);
            $previousOffset = $offset;

            $date = self::extractDate($block);
            if ($date === null) {
                $failed[] = ['line' => $line, 'reason' => 'Data inválida.'];

                continue;
            }

            $amountRaw = self::tag($block, 'TRNAMT');
            $cents = $amountRaw === null ? null : BrazilianNumber::toCents($amountRaw);
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

            $description = self::combineNameMemo(self::tag($block, 'NAME'), self::tag($block, 'MEMO'));

            $installment = null;
            if ($creditCard && $direction === Direction::Out) {
                [$description, $installment] = InstallmentSuffix::extract($description);
            }

            $fitId = self::tag($block, 'FITID');
            $externalId = $this->resolveExternalId($fitId, $usedGivenIds, $counts, $this->format(), $date, $amount, $direction, $description);

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

    private static function tag(string $block, string $name): ?string
    {
        if (! preg_match('/<'.$name.'>([^<\r\n]*)/i', $block, $m)) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private static function extractDate(string $block): ?string
    {
        $raw = self::tag($block, 'DTPOSTED');
        if ($raw === null || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m)) {
            return null;
        }

        [, $year, $month, $day] = $m;

        if (! self::yearInBounds((int) $year) || ! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private static function combineNameMemo(?string $name, ?string $memo): string
    {
        $name = $name === '' ? null : $name;
        $memo = $memo === '' ? null : $memo;

        if ($name !== null && $memo !== null && TextNormalizer::normalize($name) === TextNormalizer::normalize($memo)) {
            $memo = null;
        }

        return self::joinDescriptionParts([$name, $memo]);
    }
}
