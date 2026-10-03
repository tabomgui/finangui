<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class NotACreditCard extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta conta não é um cartão de crédito.');
    }

    public function errorCode(): string
    {
        return 'not_a_credit_card';
    }
}
