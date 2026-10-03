<?php

namespace App\Domain\Imports\Enums;

use App\Domain\Transactions\Enums\TransactionSource;

enum ImportFormat: string
{
    case Inter = 'inter';
    case Nubank = 'nubank';
    case NubankCard = 'nubank_card';
    case C6 = 'c6';
    case Ofx = 'ofx';

    public function source(): TransactionSource
    {
        return $this === self::Ofx ? TransactionSource::Ofx : TransactionSource::Csv;
    }

    public function label(): string
    {
        return match ($this) {
            self::Inter => 'Inter (conta)',
            self::Nubank => 'Nubank (conta)',
            self::NubankCard => 'Nubank (cartão)',
            self::C6 => 'C6 (conta)',
            self::Ofx => 'OFX',
        };
    }
}
