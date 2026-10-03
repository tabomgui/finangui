<?php

use App\Domain\Imports\Parsers\NubankCardParser;
use App\Domain\Transactions\Enums\Direction;

it('reconhece o próprio cabeçalho e rejeita os outros formatos', function () {
    $parser = new NubankCardParser;

    expect($parser->accepts(importFixture('nubank-card.csv')))->toBeTrue()
        ->and($parser->accepts(importFixture('inter.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('c6.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('conta.ofx')))->toBeFalse();
});

it('conta linhas válidas e falhas', function () {
    $result = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);

    expect($result->rows)->toHaveCount(4)
        ->and($result->failed)->toBe([
            ['line' => 6, 'reason' => 'Valor zerado.'],
            ['line' => 7, 'reason' => 'Data inválida.'],
        ]);
});

it('monta a primeira linha completa', function () {
    $result = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);
    $first = $result->rows[0];

    expect($first->line)->toBe(2)
        ->and($first->date)->toBe('2026-03-02')
        ->and($first->amount)->toBe(5990)
        ->and($first->direction)->toBe(Direction::Out)
        ->and($first->description)->toBe('Loja Ficticia')
        ->and($first->externalId)->toStartWith('h:');
});

it('valor positivo é compra (saída) e negativo é pagamento/estorno (entrada)', function () {
    $result = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);

    expect($result->rows[0]->direction)->toBe(Direction::Out)
        ->and($result->rows[2]->direction)->toBe(Direction::In)
        ->and($result->rows[3]->direction)->toBe(Direction::In);
});

it('com $creditCard = true extrai a parcela e limpa a descrição', function () {
    $result = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);
    $row = $result->rows[1];

    expect($row->description)->toBe('Notebook Exemplo')
        ->and($row->installment)->toBe(['number' => 2, 'total' => 10]);
});

it('com $creditCard = false mantém a descrição inteira e installment nulo', function () {
    $result = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), false);
    $row = $result->rows[1];

    expect($row->description)->toBe('Notebook Exemplo - Parcela 2/10')
        ->and($row->installment)->toBeNull();
});

it('reimportar o mesmo conteúdo gera os mesmos ids', function () {
    $a = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);
    $b = (new NubankCardParser)->parse(importFixture('nubank-card.csv'), true);

    expect(array_map(fn ($row) => $row->externalId, $a->rows))
        ->toBe(array_map(fn ($row) => $row->externalId, $b->rows));
});
