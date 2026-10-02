<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class StatementAlreadyPaid extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta fatura já está paga.');
    }

    public function errorCode(): string
    {
        return 'statement_already_paid';
    }
}
