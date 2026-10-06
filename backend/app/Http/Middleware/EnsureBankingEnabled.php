<?php

namespace App\Http\Middleware;

use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Models\BankCredential;
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
 * BankCredential::isVerifiedFor() basta aqui — não precisa montar um
 * provedor (e arriscar o problema de descriptografia que
 * App\Domain\Banking\Providers\Pluggy\PluggyProviderFactory trata) só para
 * checar se o usuário tem credencial.
 */
final class EnsureBankingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! BankCredential::isVerifiedFor($user)) {
            throw new BankingDisabled;
        }

        return $next($request);
    }
}
