<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * A conexão ainda está `pending_link` (o vínculo de contas nunca terminou):
 * sincronizar ou marcar como reconectada não fazem sentido antes disso. Ver
 * App\Domain\Banking\Errors\ConnectionNotPendingLink para o sentido
 * contrário (vincular de novo uma conexão que já saiu de `pending_link`).
 */
final class ConnectionNotLinked extends DomainError
{
    public function __construct()
    {
        parent::__construct('Vincule as contas desta conexão antes de sincronizar.');
    }

    public function errorCode(): string
    {
        return 'connection_not_linked';
    }
}
