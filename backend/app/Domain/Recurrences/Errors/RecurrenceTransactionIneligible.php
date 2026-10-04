<?php

namespace App\Domain\Recurrences\Errors;

use App\Domain\Shared\DomainError;

final class RecurrenceTransactionIneligible extends DomainError
{
    public function __construct()
    {
        parent::__construct('Este lançamento não pode virar o início de uma recorrência.');
    }

    public function errorCode(): string
    {
        return 'recurrence_transaction_ineligible';
    }
}
