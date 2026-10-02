<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class StatementAccountMismatch extends DomainError
{
    public function __construct()
    {
        parent::__construct('A fatura escolhida não é deste cartão.');
    }

    public function errorCode(): string
    {
        return 'statement_account_mismatch';
    }
}
