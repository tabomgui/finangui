<?php

namespace App\Domain\Goals\Errors;

use App\Domain\Shared\DomainError;

final class GoalHasContributions extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta meta tem aportes; remova-os antes de acompanhar uma conta.');
    }

    public function errorCode(): string
    {
        return 'goal_has_contributions';
    }
}
