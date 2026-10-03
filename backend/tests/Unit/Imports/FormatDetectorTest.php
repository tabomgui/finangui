<?php

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Parsers\C6Parser;
use App\Domain\Imports\Parsers\InterParser;
use App\Domain\Imports\Parsers\NubankCardParser;
use App\Domain\Imports\Parsers\NubankParser;
use App\Domain\Imports\Parsers\OfxParser;
use App\Domain\Imports\Support\FormatDetector;

it('detecta cada fixture pelo próprio formato', function (string $fixture, ImportFormat $expected) {
    $raw = file_get_contents(base_path("tests/Fixtures/imports/{$fixture}"));

    expect(FormatDetector::detect($raw))->toBe($expected);
})->with([
    ['inter.csv', ImportFormat::Inter],
    ['nubank.csv', ImportFormat::Nubank],
    ['nubank-card.csv', ImportFormat::NubankCard],
    ['c6.csv', ImportFormat::C6],
    ['conta.ofx', ImportFormat::Ofx],
    ['cartao.ofx', ImportFormat::Ofx],
]);

it('retorna null para conteúdo desconhecido', function () {
    expect(FormatDetector::detect("qualquer,coisa\n1,2"))->toBeNull();
});

it('resolve o parser de cada formato', function () {
    expect(FormatDetector::parser(ImportFormat::Inter))->toBeInstanceOf(InterParser::class)
        ->and(FormatDetector::parser(ImportFormat::Nubank))->toBeInstanceOf(NubankParser::class)
        ->and(FormatDetector::parser(ImportFormat::NubankCard))->toBeInstanceOf(NubankCardParser::class)
        ->and(FormatDetector::parser(ImportFormat::C6))->toBeInstanceOf(C6Parser::class)
        ->and(FormatDetector::parser(ImportFormat::Ofx))->toBeInstanceOf(OfxParser::class);
});
