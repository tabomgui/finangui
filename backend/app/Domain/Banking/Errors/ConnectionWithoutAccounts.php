<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * O provedor devolveu o item sem nenhuma conta (GET /accounts vazio): nada
 * para vincular, a conexão nem chega a ser criada.
 */
final class ConnectionWithoutAccounts extends DomainError
{
    public function __construct()
    {
        parent::__construct('O banco não retornou contas para vincular.');
    }

    public function errorCode(): string
    {
        return 'connection_without_accounts';
    }
}
