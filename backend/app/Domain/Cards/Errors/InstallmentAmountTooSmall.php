<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class InstallmentAmountTooSmall extends DomainError
{
    public function __construct()
    {
        parent::__construct('O valor é pequeno demais para o número de parcelas.');
    }

    public function errorCode(): string
    {
        return 'installment_amount_too_small';
    }
}
