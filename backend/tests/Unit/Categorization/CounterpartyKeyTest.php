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
