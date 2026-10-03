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

it('parseia um OFX sintético com 7000 blocos rapidamente (contagem de linha incremental, não um re-scan por bloco)', function () {
    $blocks = [];
    for ($i = 1; $i <= 7000; $i++) {
        $blocks[] = "<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-".sprintf('%d.%02d', intdiv($i, 100) + 1, $i % 100)."\n<FITID>FIT-{$i}\n<NAME>Loja {$i}\n";
    }
    $content = "<OFX>\n<BANKTRANLIST>\n".implode('', $blocks).'</BANKTRANLIST>';

    $start = microtime(true);
    $result = (new OfxParser)->parse($content, false);
    $elapsed = microtime(true) - $start;

    expect($result->rows)->toHaveCount(7000)
        ->and($result->failed)->toBe([])
        ->and($elapsed)->toBeLessThan(1.0);
});

it('lê o último bloco mesmo sem nenhuma tag de fechamento (arquivo truncado)', function () {
    $content = "<OFX>\n<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-10.00\n<FITID>FIT-TRUNC";

    $result = (new OfxParser)->parse($content, false);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->externalId)->toBe('FIT-TRUNC')
        ->and($result->rows[0]->amount)->toBe(1000);
});

it('lê blocos SGML consecutivos mesmo sem tag de fechamento de bloco nem de lista envolvente', function () {
    $content = "<OFX>\n<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-10.00\n<FITID>FIT-1\n<STMTTRN>\n<DTPOSTED>20260306\n<TRNAMT>20.00\n<FITID>FIT-2";

    $result = (new OfxParser)->parse($content, false);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0]->externalId)->toBe('FIT-1')
        ->and($result->rows[1]->externalId)->toBe('FIT-2');
});

it('reporta falha em vez de devolver vazio silenciosamente quando o regex não consegue ler o conteúdo', function () {
    $content = '<OFX>'.str_repeat('<STMTTRN>abcdefgh', 50).'</BANKTRANLIST>';

    $previous = ini_set('pcre.backtrack_limit', '1');

    try {
        $result = (new OfxParser)->parse($content, false);

        expect($result->rows)->toBe([])
            ->and($result->failed)->toBe([
                ['line' => 1, 'reason' => 'Não foi possível ler o arquivo OFX.'],
            ]);
    } finally {
        ini_set('pcre.backtrack_limit', $previous);
    }
});

it('usa o id sintético quando o FITID se repete no mesmo arquivo', function () {
    $content = "<OFX>\n<BANKTRANLIST>\n"
        ."<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-10.00\n<FITID>FIT-DUP\n<NAME>Loja A\n"
        ."<STMTTRN>\n<DTPOSTED>20260306\n<TRNAMT>-20.00\n<FITID>FIT-DUP\n<NAME>Loja B\n"
        .'</BANKTRANLIST>';

    $result = (new OfxParser)->parse($content, false);

    expect($result->rows[0]->externalId)->toBe('FIT-DUP')
        ->and($result->rows[1]->externalId)->not->toBe('FIT-DUP')
        ->and($result->rows[1]->externalId)->toStartWith('h:');
});

it('usa o hash do FITID quando ele passa de 255 caracteres', function () {
    $longId = str_repeat('a', 300);
    $content = "<OFX>\n<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-10.00\n<FITID>{$longId}\n".'</BANKTRANLIST>';

    $result = (new OfxParser)->parse($content, false);

    expect(mb_strlen($result->rows[0]->externalId))->toBeLessThan(300)
        ->and($result->rows[0]->externalId)->toStartWith('h:');
});

it('não extrai parcela quando a direção é entrada, mesmo com o texto de parcela no MEMO', function () {
    $content = "<OFX>\n<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>10.00\n<FITID>FIT-1\n<MEMO>ESTORNO PARC 02/06\n".'</BANKTRANLIST>';

    $result = (new OfxParser)->parse($content, true);

    expect($result->rows[0]->direction)->toBe(Direction::In)
        ->and($result->rows[0]->description)->toBe('ESTORNO PARC 02/06')
        ->and($result->rows[0]->installment)->toBeNull();
});

it('decodifica entidades HTML em NAME/MEMO', function () {
    $content = "<OFX>\n<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>20260305\n<TRNAMT>-10.00\n<FITID>FIT-1\n<NAME>Padaria &amp; Confeitaria\n".'</BANKTRANLIST>';

    $result = (new OfxParser)->parse($content, false);

    expect($result->rows[0]->description)->toBe('Padaria & Confeitaria');
});

it('não aceita conteúdo que só menciona <STMTTRN> sem nenhum marcador de arquivo OFX', function () {
    $content = 'um texto qualquer que por acaso cita <STMTTRN> mas não é um arquivo OFX';

    expect((new OfxParser)->accepts($content))->toBeFalse();
});

it('rejeita datas fora do intervalo 1900-2100', function () {
    $content = "<OFX>\n<BANKTRANLIST>\n<STMTTRN>\n<DTPOSTED>18990305\n<TRNAMT>-10.00\n<FITID>FIT-1\n".'</BANKTRANLIST>';

    $result = (new OfxParser)->parse($content, false);

    expect($result->rows)->toBe([])
        ->and($result->failed)->toBe([
            ['line' => 3, 'reason' => 'Data inválida.'],
        ]);
});
