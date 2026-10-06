<?php

namespace App\Http\Middleware;

use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia toda rota de bank-connections antes de qualquer validação de
 * payload quando o usuário autenticado não tem credenciais da Pluggy
 * verificadas: sem isso, um FormRequest com campos obrigatórios (ex.:
 * LinkAccountsRequest) resolveria sua validação antes do controller rodar e
 * responderia 422 em vez do 409 banking_disabled esperado.
 * BankProviderFactory::for() já lança BankingDisabled quando faltam
 * credenciais — só precisamos deixá-la propagar.
 */
final class EnsureBankingEnabled
{
    public function __construct(private readonly BankProviderFactory $providerFactory) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->providerFactory->for($user);

        return $next($request);
    }
}
