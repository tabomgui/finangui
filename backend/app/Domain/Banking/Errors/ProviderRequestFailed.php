<?php

namespace App\Domain\Banking\Errors;

use App\Domain\Shared\DomainError;

/**
 * O provedor recusou a operação com um 4xx inesperado (fora 401, tratado
 * internamente pelo provedor). Usado nas ações interativas (ex.: gerar
 * connect token, deletar item).
 */
final class ProviderRequestFailed extends DomainError
{
    public function __construct()
    {
        parent::__construct('O banco recusou a operação. Tente de novo mais tarde.');
    }

    public function errorCode(): string
    {
        return 'provider_request_failed';
    }
}
