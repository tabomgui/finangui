<?php

namespace App\Domain\Transfers\Errors;

use App\Domain\Shared\DomainError;

final class TransferCurrencyMismatch extends DomainError
{
    public function __construct()
    {
        parent::__construct('Transferências entre moedas diferentes ainda não são suportadas.');
    }

    public function errorCode(): string
    {
        return 'transfer_currency_mismatch';
    }
}
