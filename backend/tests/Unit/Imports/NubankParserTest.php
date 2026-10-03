<?php

use App\Domain\Imports\Parsers\NubankParser;
use App\Domain\Imports\Support\Content;
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

it('lê CRLF (gerado aqui, não commitado) depois de normalizar', function () {
    $content = Content::normalize("Data,Valor,Identificador,Descrição\r\n05/03/2026,-45.90,id-1,Mercado Exemplo\r\n");

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->description)->toBe('Mercado Exemplo');
});

it('reconhece o cabeçalho sem acento e em caixa baixa', function () {
    $content = "data,valor,identificador,descricao\n05/03/2026,-45.90,id-1,Mercado Exemplo";

    $parser = new NubankParser;

    expect($parser->accepts($content))->toBeTrue();

    $result = $parser->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->failed)->toBe([]);
});

it('falha com "Cabeçalho não encontrado." quando nenhuma linha casa com o cabeçalho esperado', function () {
    $content = "a,b,c\n1,2,3";

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 1, 'reason' => 'Cabeçalho não encontrado.'],
        ]);
});

it('pula linha de saldo/total mesmo com data válida, sem contar como falha', function () {
    $content = "Data,Valor,Identificador,Descrição\n"
        ."05/03/2026,-45.90,id-1,Mercado Exemplo\n"
        .'31/03/2026,0.00,,Total do período';

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->failed)->toBe([]);
});

it('rejunta por vírgula a descrição que fragmentou em células extras (sem aspas no export)', function () {
    $content = "Data,Valor,Identificador,Descrição\n05/03/2026,-45.90,id-1,Loja Exemplo, Centro, SP";

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows[0]->description)->toBe('Loja Exemplo,Centro,SP');
});

it('usa o id sintético quando o Identificador se repete no mesmo arquivo', function () {
    $content = "Data,Valor,Identificador,Descrição\n"
        ."05/03/2026,-45.90,id-repetido,Mercado Exemplo\n"
        .'06/03/2026,-10.00,id-repetido,Outra Loja';

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows[0]->externalId)->toBe('id-repetido')
        ->and($result->rows[1]->externalId)->not->toBe('id-repetido')
        ->and($result->rows[1]->externalId)->toStartWith('h:');
});

it('usa o hash do id quando o Identificador do arquivo passa de 255 caracteres', function () {
    $longId = str_repeat('a', 300);
    $content = "Data,Valor,Identificador,Descrição\n05/03/2026,-45.90,{$longId},Mercado Exemplo";

    $result = (new NubankParser)->parse($content, false);

    expect(mb_strlen($result->rows[0]->externalId))->toBeLessThan(300)
        ->and($result->rows[0]->externalId)->toStartWith('h:');
});

it('rejeita datas fora do intervalo 1900-2100', function () {
    $content = "Data,Valor,Identificador,Descrição\n05/03/1899,-45.90,id-1,Mercado Exemplo";

    $result = (new NubankParser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 2, 'reason' => 'Data inválida.'],
        ]);
});
