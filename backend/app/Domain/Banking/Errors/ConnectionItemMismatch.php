<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * O item devolvido pelo widget não serve para abrir/criar esta conexão:
 * clientUserId não bate com o usuário atual, ou o item já tem uma conexão
 * (de qualquer usuário — external_id é único por provedor em todo o
 * sistema, ver migration de bank_connections).
 */
final class ConnectionItemMismatch extends DomainError
{
    public function __construct()
    {
        parent::__construct('Este item não pode ser usado para criar esta conexão.');
    }

    public function errorCode(): string
    {
        return 'connection_item_mismatch';
    }
}
