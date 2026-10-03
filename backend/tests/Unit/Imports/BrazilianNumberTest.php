<?php

use App\Domain\Imports\Support\BrazilianNumber;

it('converte número no formato brasileiro com separador de milhar', function () {
    expect(BrazilianNumber::toCents('1.234,56'))->toBe(123456);
});

it('converte número negativo com vírgula decimal', function () {
    expect(BrazilianNumber::toCents('-50,00'))->toBe(-5000);
});

it('aceita prefixo R$ e um único dígito decimal', function () {
    expect(BrazilianNumber::toCents('R$ 10,5'))->toBe(1050);
});

it('aceita apenas ponto como decimal quando tem 1 ou 2 casas', function () {
    expect(BrazilianNumber::toCents('1234.56'))->toBe(123456)
        ->and(BrazilianNumber::toCents('-30.78'))->toBe(-3078);
});

it('não suporta formato americano com vírgula de milhar e ponto decimal', function () {
    expect(BrazilianNumber::toCents('1,234.56'))->toBeNull();
});

it('converte zero corretamente', function () {
    expect(BrazilianNumber::toCents('0,00'))->toBe(0);
});

it('retorna null para vazio ou lixo', function () {
    expect(BrazilianNumber::toCents(''))->toBeNull()
        ->and(BrazilianNumber::toCents('   '))->toBeNull()
        ->and(BrazilianNumber::toCents('abc'))->toBeNull()
        ->and(BrazilianNumber::toCents('12,34,56'))->toBeNull();
});
