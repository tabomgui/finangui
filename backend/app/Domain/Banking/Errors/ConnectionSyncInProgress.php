<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

final class ConnectionSyncInProgress extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta conexão já está sincronizando. Aguarde terminar.');
    }

    public function errorCode(): string
    {
        return 'connection_sync_in_progress';
    }
}
