<?php

namespace App\Http\Middleware;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Errors\BankingDisabled;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia toda rota de bank-connections antes de qualquer validação de
 * payload quando o provedor não está configurado: sem isso, um FormRequest
 * com campos obrigatórios (ex.: LinkAccountsRequest) resolveria sua
 * validação antes do controller rodar e responderia 422 em vez do 409
 * banking_disabled esperado.
 */
final class EnsureBankingEnabled
{
    public function __construct(private readonly BankProvider $provider) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->provider->enabled()) {
            throw new BankingDisabled;
        }

        return $next($request);
    }
}
