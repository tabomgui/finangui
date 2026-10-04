<?php

use App\Domain\Imports\Support\InstallmentSuffix;

it('reconhece "- Parcela N/M" no fim da descrição', function () {
    expect(InstallmentSuffix::extract('Loja Tal - Parcela 2/10'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});

it('reconhece "Parcela N/M" sem hífen no fim da descrição', function () {
    expect(InstallmentSuffix::extract('Loja Tal Parcela 2/10'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});

it('reconhece "PARC NN/MM" com espaço', function () {
    expect(InstallmentSuffix::extract('Loja Tal PARC 02/10'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});

it('reconhece "PARCNN/MM" sem espaço', function () {
    expect(InstallmentSuffix::extract('Loja Tal PARC02/10'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});

it('reconhece "(N/M)" entre parênteses', function () {
    expect(InstallmentSuffix::extract('Loja Tal (2/10)'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});

it('a descrição inteira pode ser só o sufixo', function () {
    expect(InstallmentSuffix::extract('Parcela 2/10'))
        ->toBe(['', ['number' => 2, 'total' => 10]]);
});

it('não reconhece "N/M" sem palavra-chave no meio da descrição', function () {
    expect(InstallmentSuffix::extract('Loja 2/10 Centro'))
        ->toBe(['Loja 2/10 Centro', null]);
});

it('não reconhece número de parcela zero', function () {
    expect(InstallmentSuffix::extract('Loja Tal Parcela 0/3'))
        ->toBe(['Loja Tal Parcela 0/3', null]);
});

it('não reconhece número de parcela maior que o total', function () {
    expect(InstallmentSuffix::extract('Loja Tal Parcela 4/3'))
        ->toBe(['Loja Tal Parcela 4/3', null]);
});

it('não deixa hífen sobrando ao remover o sufixo', function () {
    expect(InstallmentSuffix::extract('Loja Tal - PARC 02/10'))
        ->toBe(['Loja Tal', ['number' => 2, 'total' => 10]]);
});
