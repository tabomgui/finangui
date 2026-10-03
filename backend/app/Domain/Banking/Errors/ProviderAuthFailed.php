<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * Credenciais do provedor (client_id/client_secret) recusadas por ele, ou a
 * API key recusada de novo mesmo depois de renovada uma vez: falha
 * permanente, não adianta tentar de novo sem trocar o segredo — diferente
 * de ProviderUnavailable (transitória).
 */
final class ProviderAuthFailed extends DomainError
{
    public function __construct()
    {
        parent::__construct('Integração bancária indisponível no momento.');
    }

    public function errorCode(): string
    {
        return 'provider_auth_failed';
    }
}
