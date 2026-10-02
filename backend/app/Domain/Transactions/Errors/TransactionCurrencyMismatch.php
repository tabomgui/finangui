<?php

namespace App\Domain\Transactions\Errors;

use App\Domain\Shared\DomainError;

final class TransactionCurrencyMismatch extends DomainError
{
    public function __construct()
    {
        parent::__construct('A conta de destino usa outra moeda.');
    }

    public function errorCode(): string
    {
        return 'transaction_currency_mismatch';
    }
}
