<?php

namespace App\Domain\Shared;

use RuntimeException;

/**
 * Violação de regra de negócio. Renderizada como HTTP 409 com {code, message};
 * o frontend decide o toast pelo `code`.
 */
abstract class DomainError extends RuntimeException
{
    abstract public function errorCode(): string;
}
