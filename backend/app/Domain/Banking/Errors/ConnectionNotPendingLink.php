<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * Guarda de status em torno do vínculo de contas: lançado tanto quando uma
 * ação exige a conexão em `pending_link` e ela já saiu desse estado
 * (vincular de novo numa conexão já `active`) quanto no sentido contrário
 * (sincronizar antes do vínculo terminar) — a mensagem muda por chamada,
 * o código de erro (usado pelo frontend para o toast) é o mesmo nos dois
 * casos.
 */
final class ConnectionNotPendingLink extends DomainError
{
    public function __construct(string $message = 'Esta conexão já foi vinculada.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'connection_not_pending_link';
    }
}
