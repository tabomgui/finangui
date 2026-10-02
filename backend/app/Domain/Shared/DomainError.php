<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Violação de regra de negócio. Renderizada como HTTP 409 com {code, message};
 * o frontend decide o toast pelo `code`.
 */
abstract class DomainError extends RuntimeException
{
    /**
     * Precisa ser uma constante por classe (não pode depender de estado de
     * instância/construtor): a doc de OpenAPI (DomainErrorToResponseExtension)
     * lê esse valor via `(new ReflectionClass($class))->newInstanceWithoutConstructor()`.
     */
    abstract public function errorCode(): string;
}
