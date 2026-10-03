<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class InstallmentLocked extends DomainError
{
    public function __construct()
    {
        parent::__construct('Conta, valor, data e tipo de uma parcela seguem o parcelamento e não podem ser alterados.');
    }

    public function errorCode(): string
    {
        return 'installment_locked';
    }
}
