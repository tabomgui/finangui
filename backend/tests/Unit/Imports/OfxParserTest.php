<?php

use App\Domain\Imports\Parsers\OfxParser;
use App\Domain\Transactions\Enums\Direction;

it('reconhece o próprio formato (SGML ou XML) e rejeita os CSVs', function () {
    $parser = new OfxParser;

    expect($parser->accepts(importFixture('conta.ofx')))->toBeTrue()
        ->and($parser->accepts(importFixture('cartao.ofx')))->toBeTrue()
        ->and($parser->accepts(importFixture('inter.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('nubank-card.csv')))->toBeFalse()
        ->and($parser->accepts(importFixture('c6.csv')))->toBeFalse();
});

it('conta linhas válidas e falhas no SGML 1.x', function () {
    $result = (new OfxParser)->parse(importFixture('conta.ofx'), false);

    expect($result->rows)->toHaveCount(2)
        ->and($result->failed)->toBe([
            ['line' => 54, 'reason' => 'Valor zerado.'],
            ['line' => 61, 'reason' => 'Data inválida.'],
        ]);
});

it('monta a primeira linha completa, com NAME e MEMO diferentes', function () {
    $result = (new OfxParser)->parse(importFixture('conta.ofx'), false);
    $first = $result->rows[0];

    expect($first->line)->toBe(39)
        ->and($first->date)->toBe('2026-03-05')
        ->and($first->amount)->toBe(1590)
        ->and($first->direction)->toBe(Direction::Out)
        ->and($first->description)->toBe('PIX ENVIADO - Padaria Exemplo')
        ->and($first->externalId)->toBe('FITID-0001');
});

it('DTPOSTED com fuso horário e sem fuso horário são lidos igual', function () {
    $result = (new OfxParser)->parse(importFixture('conta.ofx'), false);

    expect($result->rows[0]->date)->toBe('2026-03-05')
        ->and($result->rows[1]->date)->toBe('2026-03-06')
        ->and($result->rows[1]->description)->toBe('PIX RECEBIDO')
        ->and($result->rows[1]->externalId)->toBe('FITID-0002');
});

it('TRNAMT negativo é saída, FITID vira externalId', function () {
    $result = (new OfxParser)->parse(importFixture('conta.ofx'), false);

    expect($result->rows[0]->direction)->toBe(Direction::Out)
        ->and($result->rows[1]->direction)->toBe(Direction::In);
});

it('lê o XML 2.x e combina NAME/MEMO quando só um está presente', function () {
    $result = (new OfxParser)->parse(importFixture('cartao.ofx'), false);

    expect($result->rows)->toHaveCount(2)
        ->and($result->failed)->toBe([])
        ->and($result->rows[0]->description)->toBe('COMPRA EXEMPLO PARC 03/06')
        ->and($result->rows[1]->description)->toBe('PAGAMENTO RECEBIDO');
});

it('PARC NN/MM vira parcela em cartão quando $creditCard é true', function () {
    $result = (new OfxParser)->parse(importFixture('cartao.ofx'), true);
    $row = $result->rows[0];

    expect($row->description)->toBe('COMPRA EXEMPLO')
        ->and($row->installment)->toBe(['number' => 3, 'total' => 6])
        ->and($row->direction)->toBe(Direction::Out)
        ->and($row->amount)->toBe(35000)
        ->and($row->externalId)->toBe('FITID-CARD-0001');
});

it('não extrai parcela quando $creditCard é false', function () {
    $result = (new OfxParser)->parse(importFixture('cartao.ofx'), false);
    $row = $result->rows[0];

    expect($row->description)->toBe('COMPRA EXEMPLO PARC 03/06')
        ->and($row->installment)->toBeNull();
});

it('reimportar o mesmo conteúdo gera os mesmos ids', function () {
    $a = (new OfxParser)->parse(importFixture('conta.ofx'), false);
    $b = (new OfxParser)->parse(importFixture('conta.ofx'), false);

    expect(array_map(fn ($row) => $row->externalId, $a->rows))
        ->toBe(array_map(fn ($row) => $row->externalId, $b->rows));
});
