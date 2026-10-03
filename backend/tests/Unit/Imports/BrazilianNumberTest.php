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

it('aceita sinal de "+" explícito', function () {
    expect(BrazilianNumber::toCents('+1.234,56'))->toBe(123456)
        ->and(BrazilianNumber::toCents('+30.78'))->toBe(3078);
});

it('ignora espaço não separável (NBSP) entre o "R$" e o valor', function () {
    expect(BrazilianNumber::toCents("R$\u{A0}1.234,56"))->toBe(123456);
});

it('rejeita ponto decimal puro sem parte inteira ("ponto" ambíguo com milhar truncado)', function () {
    expect(BrazilianNumber::toCents('.5'))->toBeNull();
});

it('rejeita "1.234" no ramo de ponto decimal puro: 3 dígitos depois do ponto não é decimal válido', function () {
    expect(BrazilianNumber::toCents('1.234'))->toBeNull();
});

it('rejeita grupo de milhar com menos de 3 dígitos no meio do número', function () {
    expect(BrazilianNumber::toCents('1.2,3'))->toBeNull();
});

it('rejeita grupo de milhar vazio (dois pontos seguidos)', function () {
    expect(BrazilianNumber::toCents('1..2,00'))->toBeNull();
});

it('rejeita parte inteira com mais de 16 dígitos', function () {
    expect(BrazilianNumber::toCents('99999999999999999,00'))->toBeNull();
});

it('rejeita valor cujos centavos passem de 1 quatrilhão', function () {
    expect(BrazilianNumber::toCents('10000000000000,01'))->toBeNull();
});
