<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

final class BankingDisabled extends DomainError
{
    public function __construct()
    {
        parent::__construct('Cadastre suas credenciais da Pluggy em Configurações.');
    }

    public function errorCode(): string
    {
        return 'banking_disabled';
    }
}
