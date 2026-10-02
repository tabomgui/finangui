<?php

namespace App\Domain\Cards\Support;

use App\Domain\Cards\Errors\InstallmentAmountTooSmall;
use App\Support\Money\Money;

final class InstallmentSplit
{
    /**
     * Total ÷ N; os centavos de resto vão para a primeira parcela.
     *
     * @return list<Money>
     *
     * @throws InstallmentAmountTooSmall
     */
    public static function split(Money $total, int $count): array
    {
        if ($count < 1 || $total->cents < $count) {
            throw new InstallmentAmountTooSmall;
        }

        $base = intdiv($total->cents, $count);
        $parts = array_fill(0, $count, Money::cents($base));
        $parts[0] = Money::cents($base + $total->cents - $base * $count);

        return $parts;
    }
}
