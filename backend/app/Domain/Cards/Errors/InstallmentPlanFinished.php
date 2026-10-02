<?php

namespace App\Domain\Cards\Errors;

use App\Domain\Shared\DomainError;

final class InstallmentPlanFinished extends DomainError
{
    public function __construct()
    {
        parent::__construct('Este parcelamento não tem parcelas futuras. Altere a categoria em cada lançamento.');
    }

    public function errorCode(): string
    {
        return 'installment_plan_finished';
    }
}
