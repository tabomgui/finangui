<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\C6Parser;
use App\Domain\Imports\Parsers\InterParser;
use App\Domain\Imports\Parsers\NubankCardParser;
use App\Domain\Imports\Parsers\NubankParser;
use App\Domain\Imports\Parsers\OfxParser;
use App\Domain\Imports\Parsers\Parser;

/**
 * Descobre o formato de um arquivo importado pelo cabeçalho/estrutura,
 * sem depender da extensão do arquivo.
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
        $normalized = Content::normalize($content);

        foreach (self::ORDER as $format) {
            if (self::parser($format)->accepts($normalized)) {
                return $format;
            }
        }

        return null;
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
}
