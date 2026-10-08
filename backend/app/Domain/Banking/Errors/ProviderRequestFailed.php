<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * O provedor recusou a operação com um 4xx inesperado (fora 401, tratado
 * internamente pelo provedor, e fora 400/401/403 em /auth, que é
 * ProviderAuthFailed). Usado nas ações interativas (ex.: gerar connect
 * token, deletar item).
 */
final class ProviderRequestFailed extends DomainError
{
    public function __construct(
        public readonly int $status = 0,
        public readonly ?string $providerCode = null,
        // Corpo "message" cru da resposta (ex.: "MeuPluggy item cant be
        // updated" em PATCH /items/{id}) — só para App\Domain\Banking\Support\ItemRefresher
        // reconhecer um erro específico do provedor; nunca exposto ao
        // usuário (a mensagem fixa abaixo é que vai para fora).
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct('O banco recusou a operação. Tente de novo mais tarde.');
    }

    public function errorCode(): string
    {
        return 'provider_request_failed';
    }
}
