<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * A conexão precisa de reconexão no widget (status `needs_reauth`): um
 * sync não adianta enquanto o usuário não refizer o login no banco.
 */
final class ConnectionNeedsReauth extends DomainError
{
    public function __construct()
    {
        parent::__construct('Reconecte o banco antes de sincronizar.');
    }

    public function errorCode(): string
    {
        return 'connection_needs_reauth';
    }
}
