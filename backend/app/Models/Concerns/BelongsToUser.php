<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Isola os dados por usuário: global scope por auth()->id() e user_id automático.
 *
 * Sem usuário autenticado o scope falha fechado (MissingUserContext). Fora de
 * request, rode o trabalho dentro de App\Support\UserContext::run(); acesso
 * global legítimo (seeders, factories, importação) usa withoutGlobalScopes().
 * Toda rota que toca um model com este trait fica atrás de auth:sanctum.
 */
trait BelongsToUser
{
    protected static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder) {
            if (! Auth::hasUser()) {
                throw MissingUserContext::forModel($builder->getModel()::class);
            }

            $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
        });

        static::creating(function ($model) {
            if (! $model->user_id && Auth::hasUser()) {
                $model->user_id = Auth::id();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
