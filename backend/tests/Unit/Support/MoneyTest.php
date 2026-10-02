<?php

use App\Support\Money\Money;

it('guarda centavos inteiros', function () {
    expect(Money::cents(106936)->cents)->toBe(106936);
});

it('soma e subtrai', function () {
    $a = Money::cents(1000);
    $b = Money::cents(250);

    expect($a->plus($b)->cents)->toBe(1250)
        ->and($a->minus($b)->cents)->toBe(750)
        ->and($b->minus($a)->cents)->toBe(-750);
});

it('inverte o sinal', function () {
    expect(Money::cents(500)->negated()->cents)->toBe(-500);
});

it('informa o sinal', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::cents(-1)->isNegative())->toBeTrue()
        ->and(Money::cents(1)->isPositive())->toBeTrue();
});

it('compara por valor', function () {
    expect(Money::cents(5)->equals(Money::cents(5)))->toBeTrue()
        ->and(Money::cents(5)->equals(Money::cents(6)))->toBeFalse();
});

it('serializa em JSON como centavos inteiros', function () {
    expect(json_encode(['amount' => Money::cents(42)]))->toBe('{"amount":42}');
});
