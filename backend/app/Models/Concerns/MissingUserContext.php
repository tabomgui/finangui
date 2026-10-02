<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Consulta a um model com BelongsToUser sem usuário autenticado. Fora de
 * request (job, comando, scheduler), rode dentro de UserContext::run() ou use
 * withoutGlobalScopes() de forma explícita quando o acesso é realmente global.
 */
final class MissingUserContext extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("Consulta a {$model} sem usuário autenticado. Use UserContext::run() ou withoutGlobalScopes().");
    }
}
