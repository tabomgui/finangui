<?php

namespace App\Domain\Recurrences\Errors;

use App\Domain\Shared\DomainError;

final class OccurrenceNotProjected extends DomainError
{
    public function __construct()
    {
        parent::__construct('Este lançamento não é uma ocorrência prevista de recorrência.');
    }

    public function errorCode(): string
    {
        return 'occurrence_not_projected';
    }
}
