<?php

namespace App\Domain\Imports\Errors;

use App\Domain\Shared\DomainError;

final class ImportBatchNotRevertible extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta importação não pode ser revertida.');
    }

    public function errorCode(): string
    {
        return 'import_batch_not_revertible';
    }
}
