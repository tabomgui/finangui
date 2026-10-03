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

it('falha com "Valor inválido." quando entrada e saída vêm preenchidas ao mesmo tempo', function () {
    $header = 'Data Lançamento,Data Contábil,Título,Descrição,Entrada(R$),Saída(R$),Saldo do Dia(R$)';
    $content = "{$header}\n05/03/2026,05/03/2026,AMBIGUO,AMBIGUO,10.00,5.00,0.00";

    $result = (new C6Parser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 2, 'reason' => 'Valor inválido.'],
        ]);
});

it('usa o valor absoluto quando entrada ou saída vêm negativas', function () {
    $header = 'Data Lançamento,Data Contábil,Título,Descrição,Entrada(R$),Saída(R$),Saldo do Dia(R$)';
    $content = "{$header}\n05/03/2026,05/03/2026,AJUSTE,AJUSTE,-10.00,0.00,0.00";

    $result = (new C6Parser)->parse($content, false);

    expect($result->rows[0]->direction)->toBe(Direction::In)
        ->and($result->rows[0]->amount)->toBe(1000);
});

it('reconhece o cabeçalho sem acento e em caixa baixa', function () {
    $content = "data lancamento,data contabil,titulo,descricao,entrada(r$),saida(r$),saldo do dia(r$)\n"
        .'05/03/2026,05/03/2026,PIX ENVIADO,Padaria Exemplo,0.00,15.90,100.00';

    $parser = new C6Parser;

    expect($parser->accepts($content))->toBeTrue();

    $result = $parser->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->failed)->toBe([]);
});

it('falha com "Cabeçalho não encontrado." quando nenhuma linha casa com o cabeçalho esperado', function () {
    $content = "a,b,c\n1,2,3";

    $result = (new C6Parser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 1, 'reason' => 'Cabeçalho não encontrado.'],
        ]);
});

it('pula linha de saldo/total com data válida, sem contar como falha', function () {
    $header = 'Data Lançamento,Data Contábil,Título,Descrição,Entrada(R$),Saída(R$),Saldo do Dia(R$)';
    $content = "{$header}\n"
        ."05/03/2026,05/03/2026,PIX ENVIADO,Padaria Exemplo,0.00,15.90,984.10\n"
        .'31/03/2026,31/03/2026,SALDO DO DIA,SALDO DO DIA,0.00,0.00,984.10';

    $result = (new C6Parser)->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->failed)->toBe([]);
});

it('rejeita datas fora do intervalo 1900-2100', function () {
    $header = 'Data Lançamento,Data Contábil,Título,Descrição,Entrada(R$),Saída(R$),Saldo do Dia(R$)';
    $content = "{$header}\n05/03/1899,05/03/1899,PIX ENVIADO,Padaria Exemplo,0.00,15.90,100.00";

    $result = (new C6Parser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 2, 'reason' => 'Data inválida.'],
        ]);
});
