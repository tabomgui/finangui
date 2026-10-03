<?php

namespace App\Domain\Imports\Errors;

use App\Domain\Shared\DomainError;

final class ImportBatchNotPending extends DomainError
{
    public function __construct()
    {
        parent::__construct('Este lote de importação já foi processado.');
    }

    public function errorCode(): string
    {
        return 'import_batch_not_pending';
    }
}
