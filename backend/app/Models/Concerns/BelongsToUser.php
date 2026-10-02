<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Isola os dados por usuário: global scope por auth()->id() e user_id automático.
 *
 * Sem usuário autenticado (seeders, factories, comandos) o scope não filtra.
 * Por isso toda rota que toca um model com este trait PRECISA estar atrás de
 * auth:sanctum — o middleware é a barreira real.
 */
// @phpstan-ignore trait.unused (ainda não há model de domínio consumindo o trait; entrará em uso nas próximas tasks)
trait BelongsToUser
{
    protected static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder) {
            if (Auth::hasUser()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
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
