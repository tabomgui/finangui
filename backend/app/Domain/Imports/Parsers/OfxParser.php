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
 * válido). TRNAMT negativo é sempre saída. FITID é o id próprio; quando
 * ausente, usa o sintético.
 */
final class OfxParser implements Parser
{
    use ParserHelpers;

    private const BLOCK_PATTERN = '/<STMTTRN>(.*?)(?:<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/si';

    public function format(): ImportFormat
    {
        return ImportFormat::Ofx;
    }

    public function accepts(string $content): bool
    {
        return stripos($content, '<STMTTRN') !== false;
    }

    public function parse(string $content, bool $creditCard): ParseResult
    {
        $rows = [];
        $failed = [];
        $counts = [];

        preg_match_all(self::BLOCK_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$block, $offset]) {
            $line = substr_count($content, "\n", 0, $offset) + 1;

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
            if ($creditCard) {
                [$description, $installment] = InstallmentSuffix::extract($description);
            }

            $fitId = self::tag($block, 'FITID');
            $externalId = $this->resolveExternalId($fitId, $counts, $this->format(), $date, $amount, $direction, $description);

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

        return trim($m[1]);
    }

    private static function extractDate(string $block): ?string
    {
        $raw = self::tag($block, 'DTPOSTED');
        if ($raw === null || ! preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m)) {
            return null;
        }

        [, $year, $month, $day] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private static function combineNameMemo(?string $name, ?string $memo): string
    {
        $name = $name === '' ? null : $name;
        $memo = $memo === '' ? null : $memo;

        if ($name !== null && $memo !== null) {
            return TextNormalizer::normalize($name) === TextNormalizer::normalize($memo)
                ? $name
                : $name.' - '.$memo;
        }

        return $name ?? $memo ?? '';
    }
}
