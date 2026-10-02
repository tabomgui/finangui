<?php

namespace App\Domain\Transfers\Errors;

use App\Domain\Shared\DomainError;

final class TransferLegLocked extends DomainError
{
    public function __construct()
    {
        parent::__construct('Conta, data, valor e sentido de uma transferência só mudam pela própria transferência.');
    }

    public function errorCode(): string
    {
        return 'transfer_leg_locked';
    }
}
