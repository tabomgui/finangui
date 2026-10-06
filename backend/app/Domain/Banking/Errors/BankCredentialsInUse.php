<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * O usuário tem conexões bancárias (qualquer status) que dependem da
 * credencial atual. Lançado tanto ao trocar de client_id (conta Pluggy
 * diferente — os itens cadastrados pertencem à conta antiga) quanto ao
 * remover a credencial de todo: nos dois casos, o usuário precisa
 * desconectar os bancos primeiro. Trocar só o client_secret do mesmo
 * client_id nunca cai aqui (ver App\Domain\Banking\Actions\SaveBankCredentials).
 */
final class BankCredentialsInUse extends DomainError
{
    public function __construct()
    {
        parent::__construct('Desconecte os bancos antes de trocar de conta Pluggy.');
    }

    public function errorCode(): string
    {
        return 'bank_credentials_in_use';
    }
}
