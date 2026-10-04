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

it('parse() normaliza, detecta o formato e já devolve o resultado do parser', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/imports/inter.csv'));

    $outcome = FormatDetector::parse($raw, null, false);

    expect($outcome['format'])->toBe(ImportFormat::Inter)
        ->and($outcome['result'])->not->toBeNull()
        ->and($outcome['result']->rows)->toHaveCount(4);
});

it('parse() usa o formato informado em vez de detectar, quando o chamador já sabe qual é', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/imports/nubank.csv'));

    $outcome = FormatDetector::parse($raw, ImportFormat::Nubank, false);

    expect($outcome['format'])->toBe(ImportFormat::Nubank)
        ->and($outcome['result']->rows)->not->toBeEmpty();
});

it('parse() devolve format e result nulos para conteúdo desconhecido', function () {
    $outcome = FormatDetector::parse("qualquer,coisa\n1,2", null, false);

    expect($outcome['format'])->toBeNull()
        ->and($outcome['result'])->toBeNull();
});

it('parse() normaliza Windows-1252 antes de detectar e parsear', function () {
    $raw = file_get_contents(base_path('tests/Fixtures/imports/inter-latin1.csv'));

    $outcome = FormatDetector::parse($raw, null, false);

    expect($outcome['format'])->toBe(ImportFormat::Inter)
        ->and($outcome['result']->rows)->toHaveCount(4);
});
