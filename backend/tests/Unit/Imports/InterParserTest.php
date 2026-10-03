<?php

use App\Domain\Imports\Parsers\InterParser;
use App\Domain\Transactions\Enums\Direction;

it('reconhece o próprio cabeçalho e rejeita os outros formatos', function () {
    $parser = new InterParser;

    expect($parser->accepts(importFixture('inter.csv')))->toBeTrue()
        ->and($parser->accepts(importFixture('nubank.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank-card.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('c6.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('conta.ofx')))->toBeFalse();
});

it('conta linhas válidas e falhas', function () {
    $result = (new InterParser)->parse(importFixture('inter.csv'), false);

    expect($result->rows)->toHaveCount(4)
        ->and($result->failed)->toBe([
            ['line' => 11, 'reason' => 'Data inválida.'],
        ]);
});

it('monta a primeira linha completa', function () {
    $result = (new InterParser)->parse(importFixture('inter.csv'), false);
    $first = $result->rows[0];

    expect($first->line)->toBe(7)
        ->and($first->date)->toBe('2026-03-05')
        ->and($first->amount)->toBe(1590)
        ->and($first->direction)->toBe(Direction::Out)
        ->and($first->description)->toBe('Pix enviado - Padaria Exemplo')
        ->and($first->externalId)->toStartWith('h:');
});

it('usa só o histórico quando a descrição está vazia', function () {
    $result = (new InterParser)->parse(importFixture('inter.csv'), false);
    $row = $result->rows[3];

    expect($row->description)->toBe('Compra no debito');
});

it('gera ids diferentes para duas compras iguais no mesmo dia', function () {
    $result = (new InterParser)->parse(importFixture('inter.csv'), false);

    expect($result->rows[0]->externalId)->not->toBe($result->rows[1]->externalId);
});

it('reimportar o mesmo conteúdo gera os mesmos ids', function () {
    $a = (new InterParser)->parse(importFixture('inter.csv'), false);
    $b = (new InterParser)->parse(importFixture('inter.csv'), false);

    expect(array_map(fn ($row) => $row->externalId, $a->rows))
        ->toBe(array_map(fn ($row) => $row->externalId, $b->rows));
});

it('a mesma conta, gravada em Windows-1252, gera os mesmos dados após normalizar', function () {
    $utf8 = (new InterParser)->parse(importFixture('inter.csv'), false);
    $latin1 = (new InterParser)->parse(importFixture('inter-latin1.csv'), false);

    expect(array_map(fn ($row) => $row->toArray(), $latin1->rows))
        ->toBe(array_map(fn ($row) => $row->toArray(), $utf8->rows))
        ->and($latin1->failed)->toBe($utf8->failed);
});
