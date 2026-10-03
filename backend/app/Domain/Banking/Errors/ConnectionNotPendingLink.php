<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * A conexão já saiu de `pending_link` (contas já vinculadas antes): não dá
 * para vincular de novo. Ver App\Domain\Banking\Errors\ConnectionNotLinked
 * para o sentido contrário (ação que exige a conexão já vinculada).
 */
final class ConnectionNotPendingLink extends DomainError
{
    public function __construct()
    {
        parent::__construct('Esta conexão já foi vinculada.');
    }

    public function errorCode(): string
    {
        return 'connection_not_pending_link';
    }
}
