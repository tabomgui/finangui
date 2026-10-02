<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Executa trabalho de domínio fora de request (job, comando, scheduler) como
 * se o usuário estivesse autenticado: o global scope de BelongsToUser passa a
 * filtrar por ele e user_id é preenchido nos creates. Restaura o estado
 * anterior do guard ao terminar, mesmo com exceção.
 */
final class UserContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(User $user, Closure $callback): mixed
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard();
        /** @var Authenticatable|null $previous */
        $previous = $guard->hasUser() ? $guard->user() : null;

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
