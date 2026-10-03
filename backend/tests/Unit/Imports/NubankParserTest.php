<?php

use App\Domain\Imports\Parsers\NubankParser;
use App\Domain\Transactions\Enums\Direction;

it('reconhece o próprio cabeçalho e rejeita os outros formatos', function () {
    $parser = new NubankParser;

    expect($parser->accepts(importFixture('nubank.csv')))->toBeTrue()
        ->and($parser->accepts(importFixture('inter.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank-card.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('c6.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('conta.ofx')))->toBeFalse();
});

it('conta linhas válidas e falhas', function () {
    $result = (new NubankParser)->parse(importFixture('nubank.csv'), false);

    expect($result->rows)->toHaveCount(5)
        ->and($result->failed)->toBe([
            ['line' => 7, 'reason' => 'Valor zerado.'],
            ['line' => 8, 'reason' => 'Data inválida.'],
        ]);
});

it('monta a primeira linha completa', function () {
    $result = (new NubankParser)->parse(importFixture('nubank.csv'), false);
    $first = $result->rows[0];

    expect($first->line)->toBe(2)
        ->and($first->date)->toBe('2026-03-05')
        ->and($first->amount)->toBe(4590)
        ->and($first->direction)->toBe(Direction::Out)
        ->and($first->description)->toBe('Compra no débito - Mercado Exemplo')
        ->and($first->externalId)->toBe('b1c2d3e4-0000-4000-8000-000000000001');
});

it('usa o Identificador como externalId quando presente', function () {
    $result = (new NubankParser)->parse(importFixture('nubank.csv'), false);

    expect($result->rows[1]->externalId)->toBe('b1c2d3e4-0000-4000-8000-000000000002')
        ->and($result->rows[2]->externalId)->toBe('b1c2d3e4-0000-4000-8000-000000000003');
});

it('gera id sintético quando o Identificador está vazio', function () {
    $result = (new NubankParser)->parse(importFixture('nubank.csv'), false);

    expect($result->rows[3]->externalId)->toStartWith('h:');
});

it('gera ids diferentes para duas compras iguais no mesmo dia sem Identificador', function () {
    $result = (new NubankParser)->parse(importFixture('nubank.csv'), false);

    expect($result->rows[3]->externalId)->not->toBe($result->rows[4]->externalId);
});

it('reimportar o mesmo conteúdo gera os mesmos ids', function () {
    $a = (new NubankParser)->parse(importFixture('nubank.csv'), false);
    $b = (new NubankParser)->parse(importFixture('nubank.csv'), false);

    expect(array_map(fn ($row) => $row->externalId, $a->rows))
        ->toBe(array_map(fn ($row) => $row->externalId, $b->rows));
});
