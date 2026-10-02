<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class InstallmentsRequireCard extends DomainError
{
    public function __construct()
    {
        parent::__construct('Parcelamento só existe em despesa de cartão de crédito.');
    }

    public function errorCode(): string
    {
        return 'installments_require_card';
    }
}
