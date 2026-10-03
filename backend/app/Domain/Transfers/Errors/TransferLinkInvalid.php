<?php

namespace App\Domain\Transfers\Errors;

use App\Domain\Shared\DomainError;

final class TransferLinkInvalid extends DomainError
{
    public function __construct()
    {
        parent::__construct('Essas transações não formam uma transferência.');
    }

    public function errorCode(): string
    {
        return 'transfer_link_invalid';
    }
}
