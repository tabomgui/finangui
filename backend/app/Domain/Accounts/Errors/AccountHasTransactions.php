<?php

namespace App\Domain\Accounts\Errors;

use App\Domain\Shared\DomainError;

final class AccountHasTransactions extends DomainError
{
    public function __construct()
    {
        parent::__construct('A conta tem transações. Arquive-a em vez de excluir.');
    }

    public function errorCode(): string
    {
        return 'account_has_transactions';
    }
}
