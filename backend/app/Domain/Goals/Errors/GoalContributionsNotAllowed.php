<?php

namespace App\Domain\Goals\Errors;

use App\Domain\Shared\DomainError;

final class GoalContributionsNotAllowed extends DomainError
{
    public function __construct()
    {
        parent::__construct('Metas com conta vinculada não recebem aportes manuais.');
    }

    public function errorCode(): string
    {
        return 'goal_contributions_not_allowed';
    }
}
