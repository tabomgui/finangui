<?php

use App\Domain\Imports\Parsers\C6Parser;
use App\Domain\Transactions\Enums\Direction;

it('reconhece o próprio cabeçalho e rejeita os outros formatos', function () {
    $parser = new C6Parser;

    expect($parser->accepts(importFixture('c6.csv')))->toBeTrue()
        ->and($parser->accepts(importFixture('inter.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank-card.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('conta.ofx')))->toBeFalse();
});

it('conta linhas válidas e falhas, ignorando o rodapé sem contar como falha', function () {
    $result = (new C6Parser)->parse(importFixture('c6.csv'), false);

    expect($result->rows)->toHaveCount(3)
        ->and($result->failed)->toBe([
            ['line' => 9, 'reason' => 'Valor zerado.'],
            ['line' => 10, 'reason' => 'Data inválida.'],
        ]);
});

it('monta a primeira linha completa, respeitando a vírgula dentro de aspas', function () {
    $result = (new C6Parser)->parse(importFixture('c6.csv'), false);
    $first = $result->rows[0];

    expect($first->line)->toBe(6)
        ->and($first->date)->toBe('2026-03-05')
        ->and($first->amount)->toBe(1590)
        ->and($first->direction)->toBe(Direction::Out)
        ->and($first->description)->toBe('PIX ENVIADO - Padaria Exemplo, Centro')
        ->and($first->externalId)->toStartWith('h:');
});

it('entrada maior que zero é entrada e saída maior que zero é saída', function () {
    $result = (new C6Parser)->parse(importFixture('c6.csv'), false);

    expect($result->rows[0]->direction)->toBe(Direction::Out)
        ->and($result->rows[2]->direction)->toBe(Direction::In);
});

it('não repete o título quando é igual à descrição', function () {
    $result = (new C6Parser)->parse(importFixture('c6.csv'), false);

    expect($result->rows[2]->description)->toBe('PIX RECEBIDO');
});

it('gera ids diferentes para duas compras iguais no mesmo dia', function () {
    $result = (new C6Parser)->parse(importFixture('c6.csv'), false);

    expect($result->rows[0]->externalId)->not->toBe($result->rows[1]->externalId);
});

it('reimportar o mesmo conteúdo gera os mesmos ids', function () {
    $a = (new C6Parser)->parse(importFixture('c6.csv'), false);
    $b = (new C6Parser)->parse(importFixture('c6.csv'), false);

    expect(array_map(fn ($row) => $row->externalId, $a->rows))
        ->toBe(array_map(fn ($row) => $row->externalId, $b->rows));
});
