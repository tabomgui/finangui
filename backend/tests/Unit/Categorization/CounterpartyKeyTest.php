<?php

use App\Domain\Categorization\Support\CounterpartyKey;
use App\Domain\Rules\Support\TextNormalizer;

it('tira o prefixo de pix enviado e recebido, caindo na mesma chave', function () {
    $sent = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Pix enviado  - Fulano De Tal'));
    $received = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Pix recebido - Fulano De Tal'));

    expect($sent)->toBe('FULANO DE TAL')
        ->and($received)->toBe('FULANO DE TAL')
        ->and($sent)->toBe($received);
});

it('usa o prefixo mais longo quando ele também bate', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Pix enviado devolvido Fulano'));

    expect($key)->toBe('FULANO');
});

it('tira o sufixo de cidade e país depois do prefixo', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Compra no débito - Padaria Teste Chapeco Bra'));

    expect($key)->toBe('PADARIA TESTE');
});

it('tira o sufixo de cidade e país mesmo sem nenhum prefixo reconhecido', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('ifd loja exemplo chapeco bra'));

    expect($key)->toBe('IFD LOJA EXEMPLO');
});

it('reconhece cidade com mais de uma palavra', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('loja exemplo sao paulo bra'));

    expect($key)->toBe('LOJA EXEMPLO');
});

it('vira o próprio prefixo quando não sobra nada depois dele', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Resgate'));

    expect($key)->toBe('RESGATE');
});

it('chave vazia vira vazia', function () {
    expect(CounterpartyKey::fromDescriptionKey(''))->toBe('');
});

it('mantém BRA quando não reconhece nenhuma cidade antes dele', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Loja Desconhecida Marte Bra'));

    expect($key)->toBe('LOJA DESCONHECIDA MARTE BRA');
});

it('monta o nome em título a partir da chave quando não há descrição original', function () {
    expect(CounterpartyKey::displayName('FULANO DE TAL'))->toBe('Fulano De Tal');
});

it('monta o nome a partir da descrição original, preservando a grafia', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Pix enviado  - Fulano De Tal'));

    expect(CounterpartyKey::displayName($key, 'Pix enviado  - Fulano De Tal'))->toBe('Fulano De Tal');
});

it('preserva dígitos do nome original que a chave normalizada perderia', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Compra no débito - Loja 99 Exemplo'));

    expect(CounterpartyKey::displayName($key, 'Compra no débito - Loja 99 Exemplo'))->toBe('Loja 99 Exemplo');
});

it('deixa o nome extraído em título quando a descrição original é só caixa baixa ou só caixa alta', function () {
    $lower = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('ifd loja exemplo chapeco bra'));
    $upper = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('IFD LOJA EXEMPLO CHAPECO BRA'));

    expect(CounterpartyKey::displayName($lower, 'ifd loja exemplo chapeco bra'))->toBe('Ifd Loja Exemplo')
        ->and(CounterpartyKey::displayName($upper, 'IFD LOJA EXEMPLO CHAPECO BRA'))->toBe('Ifd Loja Exemplo');
});

it('cai no título da chave quando a extração da descrição original não sobra nada', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Resgate'));

    expect(CounterpartyKey::displayName($key, 'Resgate'))->toBe('Resgate');
});

it('extrai da descrição original - PIX ENVIADO FULANO vira Fulano, mesmo com apelido salvo', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('PIX ENVIADO FULANO'));

    expect(CounterpartyKey::displayName($key, 'PIX ENVIADO FULANO'))->toBe('Fulano');
});

it('o nome extraído preserva o "*" interno de um token, sem reconstruir a partir de palavras soltas', function () {
    $original = 'SHPP*BRASIL INSTITUICAO';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    $name = CounterpartyKey::displayName($key, $original);

    // a extração é sempre um recorte de verdade do texto original: dá para
    // achar $name de volta dentro de $original comparando sem caixa.
    expect(mb_stripos($original, $name))->not->toBeFalse()
        ->and($name)->toContain('*');
});

it('preserva o hífen interno de um token ao extrair o nome', function () {
    $original = 'Auto-Posto Exemplo Chapeco BRA';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    expect(CounterpartyKey::displayName($key, $original))->toBe('Auto-Posto Exemplo');
});

it('não mantém o prefixo na extração, mesmo com vírgula colada nele', function () {
    $original = 'Compra no débito, Padaria Teste Chapecó BRA';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    expect(CounterpartyKey::displayName($key, $original))->toBe('Padaria Teste')
        ->and(CounterpartyKey::displayName($key, $original))->not->toContain('ébito');
});

it('ignora um token só de dígito logo depois do prefixo', function () {
    $original = '12/10 COMPRA NO DEBITO PADARIA';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    expect(CounterpartyKey::displayName($key, $original))->toBe('Padaria');
});

it('ignora um número tipo CPF entre o prefixo e o nome, sem virar parte do nome', function () {
    $original = 'PIX ENVIADO 123.456.789-00 FULANO';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    $name = CounterpartyKey::displayName($key, $original);

    expect($name)->toBe('Fulano')
        ->and($name)->not->toContain('123');
});

it('não trata palavra comum com o mesmo começo do prefixo como prefixo', function () {
    expect(CounterpartyKey::fromDescriptionKey('RESGATELAND'))->toBe('RESGATELAND');
});

it('não tira cidade sem o país depois', function () {
    expect(CounterpartyKey::fromDescriptionKey('LOJA SANTOS'))->toBe('LOJA SANTOS');
});

it('reconhece sufixo de cidade e UF/país estrangeiro, ex.: San Francisco CA', function () {
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Loja Exemplo San Francisco CA'));

    expect($key)->toBe('LOJA EXEMPLO');
});

it('tira o prefixo de transferência pelo pix, enviada e recebida, caindo na mesma chave', function () {
    $received = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Transferência recebida pelo Pix - EMPRESA EXEMPLO'));
    $sent = CounterpartyKey::fromDescriptionKey(TextNormalizer::key('Transferência enviada pelo Pix - EMPRESA EXEMPLO'));

    expect($received)->toBe('EMPRESA EXEMPLO')
        ->and($sent)->toBe('EMPRESA EXEMPLO');
});

it('prefere o prefixo de transferência pelo pix ao de transferência simples', function () {
    expect(CounterpartyKey::fromDescriptionKey('TRANSFERENCIA RECEBIDA PELO PIX FULANO'))->toBe('FULANO')
        ->and(CounterpartyKey::fromDescriptionKey('TRANSFERENCIA RECEBIDA FULANO'))->toBe('FULANO');
});

it('monta o nome da transferência pelo pix sem o prefixo nem o separador', function () {
    $original = 'Transferência recebida pelo Pix - EMPRESA EXEMPLO';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    expect(CounterpartyKey::displayName($key, $original))->toBe('Empresa Exemplo')
        ->and(CounterpartyKey::displayName($key, 'Transferência enviada pelo Pix - Fulano De Tal'))->toBe('Fulano De Tal');
});

it('tira um separador colado no começo do nome extraído', function () {
    $original = 'Transferência enviada pelo Pix -EMPRESA EXEMPLO';
    $key = CounterpartyKey::fromDescriptionKey(TextNormalizer::key($original));

    $name = CounterpartyKey::displayName($key, $original);

    expect($name)->toBe('Empresa Exemplo')
        ->and(mb_stripos($original, $name))->not->toBeFalse();
});
