<?php

use App\Domain\Imports\Support\TokenSimilarity;

it('é 1.0 quando todos os tokens do lado menor aparecem no outro lado', function () {
    expect(TokenSimilarity::overlap('Mercado', 'COMPRA CARTAO MERCADO EXTRA'))->toBe(1.0);
});

it('é 0.0 quando não há token em comum', function () {
    expect(TokenSimilarity::overlap('Uber', 'Pix enviado Fulano'))->toBe(0.0);
});

it('ignora acentos e caixa, como TextNormalizer::key()', function () {
    expect(TokenSimilarity::overlap('Padaria São João', 'PADARIA SAO JOAO 123'))->toBe(1.0);
});

it('ignora tokens com menos de 3 letras', function () {
    expect(TokenSimilarity::overlap('DE', 'DE PARA'))->toBe(0.0);
});

it('é 0.0 quando um dos lados não tem nenhum token válido', function () {
    expect(TokenSimilarity::overlap('123', 'Mercado Extra'))->toBe(0.0)
        ->and(TokenSimilarity::overlap('', ''))->toBe(0.0);
});

it('usa o menor conjunto como denominador', function () {
    expect(TokenSimilarity::overlap('Compra Cartao Mercado Extra', 'Mercado'))->toBe(1.0)
        ->and(TokenSimilarity::overlap('Mercado Extra', 'Mercado Exemplo'))->toBe(0.5);
});
