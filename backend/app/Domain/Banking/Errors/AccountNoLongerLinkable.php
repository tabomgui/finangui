<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * Entre a validação do pedido e a trava da conta dentro da transação (ver
 * App\Domain\Banking\Actions\LinkAccounts), a conta escolhida deixou de
 * estar disponível: outra requisição já a conectou, ou ela foi arquivada.
 * Corrida rara (TOCTOU), não um erro de validação do pedido em si.
 */
final class AccountNoLongerLinkable extends DomainError
{
    public function __construct()
    {
        parent::__construct('Uma das contas escolhidas não está mais disponível para vínculo.');
    }

    public function errorCode(): string
    {
        return 'account_no_longer_linkable';
    }
}
