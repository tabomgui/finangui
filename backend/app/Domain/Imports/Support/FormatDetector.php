<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\C6Parser;
use App\Domain\Imports\Parsers\InterParser;
use App\Domain\Imports\Parsers\NubankCardParser;
use App\Domain\Imports\Parsers\NubankParser;
use App\Domain\Imports\Parsers\OfxParser;
use App\Domain\Imports\Parsers\Parser;

/**
 * Descobre o formato de um arquivo importado pelo cabeçalho/estrutura,
 * sem depender da extensão do arquivo, e dá um único ponto de entrada
 * (parse()) que normaliza o conteúdo bruto uma só vez.
 */
final class FormatDetector
{
    /**
     * @var list<ImportFormat>
     */
    private const ORDER = [
        ImportFormat::Ofx,
        ImportFormat::NubankCard,
        ImportFormat::Nubank,
        ImportFormat::C6,
        ImportFormat::Inter,
    ];

    public static function detect(string $content): ?ImportFormat
    {
        return self::detectNormalized(Content::normalize($content));
    }

    /**
     * Normaliza o conteúdo bruto uma única vez, decide o formato (ou usa o
     * informado, quando o chamador já sabe qual é) e, se houver formato,
     * já parseia. `format` vem null quando nenhum parser reconhece o
     * conteúdo; nesse caso `result` também vem null.
     *
     * @return array{format: ?ImportFormat, result: ?ParseResult}
     */
    public static function parse(string $raw, ?ImportFormat $format, bool $creditCard): array
    {
        $normalized = Content::normalize($raw);
        $resolvedFormat = $format ?? self::detectNormalized($normalized);

        if ($resolvedFormat === null) {
            return ['format' => null, 'result' => null];
        }

        return [
            'format' => $resolvedFormat,
            'result' => self::parser($resolvedFormat)->parse($normalized, $creditCard),
        ];
    }

    public static function parser(ImportFormat $format): Parser
    {
        return match ($format) {
            ImportFormat::Inter => new InterParser,
            ImportFormat::Nubank => new NubankParser,
            ImportFormat::NubankCard => new NubankCardParser,
            ImportFormat::C6 => new C6Parser,
            ImportFormat::Ofx => new OfxParser,
        };
    }

    private static function detectNormalized(string $normalized): ?ImportFormat
    {
        foreach (self::ORDER as $format) {
            if (self::parser($format)->accepts($normalized)) {
                return $format;
            }
        }

        return null;
    }
}
