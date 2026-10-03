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
    // Sem parser de arquivo: linhas deste formato vêm do SyncConnection
    // (TransactionMapper), nunca de upload — StoreImportBatchRequest exclui
    // este caso das suas regras.
    case Pluggy = 'pluggy';

    public function source(): TransactionSource
    {
        return match ($this) {
            self::Ofx => TransactionSource::Ofx,
            self::Pluggy => TransactionSource::Pluggy,
            default => TransactionSource::Csv,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Inter => 'Inter (conta)',
            self::Nubank => 'Nubank (conta)',
            self::NubankCard => 'Nubank (cartão)',
            self::C6 => 'C6 (conta)',
            self::Ofx => 'OFX',
            self::Pluggy => 'Sincronização bancária',
        };
    }
}
