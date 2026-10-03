<?php

namespace App\Domain\Rules\Errors;

use App\Domain\Shared\DomainError;

final class RuleApplyInProgress extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta regra já está sendo aplicada. Aguarde terminar.');
    }

    public function errorCode(): string
    {
        return 'rule_apply_in_progress';
    }
}
