<?php

namespace App\Domain\Accounts\Errors;

use App\Domain\Shared\DomainError;

final class AccountTypeLocked extends DomainError
{
    public function __construct()
    {
        parent::__construct('Uma conta com lançamentos não pode virar cartão de crédito nem deixar de ser.');
    }

    public function errorCode(): string
    {
        return 'account_type_locked';
    }
}
