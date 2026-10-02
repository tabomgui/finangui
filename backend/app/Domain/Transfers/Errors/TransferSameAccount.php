<?php

namespace App\Domain\Transfers\Errors;

use App\Domain\Shared\DomainError;

final class TransferSameAccount extends DomainError
{
    public function __construct()
    {
        parent::__construct('Origem e destino da transferência precisam ser contas diferentes.');
    }

    public function errorCode(): string
    {
        return 'transfer_same_account';
    }
}
