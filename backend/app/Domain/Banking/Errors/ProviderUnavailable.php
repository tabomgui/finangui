<?php

namespace App\Domain\Banking\Errors;

use RuntimeException;
use Throwable;

/**
 * Falha transitória do provedor (5xx, 429, timeout de rede). Não é um
 * DomainError: quem lida com isso é o job de sincronização, que tenta de
 * novo com espera crescente antes de marcar a conexão como `error`.
 */
final class ProviderUnavailable extends RuntimeException
{
    /**
     * @param  int|null  $retryAfter  segundos do header Retry-After de um 429; null quando não veio ou a falha não foi um 429
     */
    public function __construct(string $message, public readonly ?int $retryAfter = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
