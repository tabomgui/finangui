<?php

use App\Domain\Banking\Support\PluggyCategoryMap;

it('acha pelo prefixo de 4 dígitos quando existe', function () {
    expect(PluggyCategoryMap::categoryFor('01010000'))->toBe('Salário')
        ->and(PluggyCategoryMap::categoryFor('12010000'))->toBe('Restaurantes')
        ->and(PluggyCategoryMap::categoryFor('12020000'))->toBe('Delivery');
});

it('o prefixo de 4 dígitos vence o de 2 quando os dois existem', function () {
    // "0509" (Pagamento de fatura) é mais específico que "05" (Transferências).
    expect(PluggyCategoryMap::categoryFor('05090000'))->toBe('Pagamento de fatura')
        ->and(PluggyCategoryMap::categoryFor('05080000'))->toBe('Transferências');
});

it('cai para o prefixo de 2 dígitos quando não há um de 4 mais específico', function () {
    expect(PluggyCategoryMap::categoryFor('16000000'))->toBe('Impostos')
        ->and(PluggyCategoryMap::categoryFor('16990000'))->toBe('Impostos')
        ->and(PluggyCategoryMap::categoryFor('03000000'))->toBe('Investimentos');
});

it('id sem nenhum prefixo conhecido devolve null', function () {
    expect(PluggyCategoryMap::categoryFor('99999999'))->toBeNull();
});

it('null ou vazio devolvem null', function () {
    expect(PluggyCategoryMap::categoryFor(null))->toBeNull()
        ->and(PluggyCategoryMap::categoryFor(''))->toBeNull();
});
