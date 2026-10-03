<?php

use App\Domain\Cards\Errors\InstallmentAmountTooSmall;
use App\Domain\Cards\Support\InstallmentSplit;
use App\Support\Money\Money;

function split(int $cents, int $count): array
{
    return array_map(fn (Money $m) => $m->cents, InstallmentSplit::split(Money::cents($cents), $count));
}

it('divide igualmente quando não sobra centavo', fn () => expect(split(120000, 10))->toBe(array_fill(0, 10, 12000)));
it('centavos de resto vão para a primeira parcela', fn () => expect(split(1000, 3))->toBe([334, 333, 333]));
it('uma parcela é o valor inteiro', fn () => expect(split(4590, 1))->toBe([4590]));
it('recusa valor menor que o número de parcelas', fn () => expect(fn () => split(5, 10))->toThrow(InstallmentAmountTooSmall::class));
