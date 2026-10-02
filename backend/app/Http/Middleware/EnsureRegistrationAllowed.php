<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia o cadastro antes de qualquer validação de payload: evita que uma
 * instância fechada revele, via erro de validação de email único, se um
 * endereço já está cadastrado.
 */
final class EnsureRegistrationAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('finangui.registration_enabled')) {
            return response()->json([
                'code' => 'registration_closed',
                'message' => 'O cadastro está fechado nesta instância.',
            ], 403);
        }

        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'session_required',
                'message' => 'Cadastro só é aceito a partir do frontend (origem stateful).',
            ], 400);
        }

        return $next($request);
    }
}
