<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Errors\RuleApplyInProgress;
use App\Domain\Rules\Jobs\ApplyRuleRetroactively;
use App\Domain\Rules\Models\Rule;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;

/**
 * Despacha ApplyRuleRetroactively, mas primeiro confere se já existe uma
 * aplicação da mesma regra em voo. Sem essa conferência, um segundo POST
 * /rules/{rule}/apply enquanto o primeiro job ainda roda cairia no lock
 * único do job (ShouldBeUnique) e seria descartado em silêncio — a API
 * responderia 202 "queued" mesmo sem nada ter sido de fato enfileirado.
 *
 * A chave vem de Illuminate\Bus\UniqueLock::getKey() (a mesma que o
 * PendingDispatch usaria ao despachar de verdade) — nunca recalculada à
 * mão, porque o nome do lock depende de o job declarar `displayName()` ou
 * não (ver UniqueLock::getKey()). O cache lock em si guarda seu estado numa
 * área própria do driver (array/database implementam LockProvider com
 * armazenamento separado do cache comum), então a única forma correta e
 * portável entre drivers de "só olhar" o lock é tentar adquiri-lo e, se
 * conseguir, liberar na hora: ninguém mais o detinha.
 */
final class QueueRuleApplication
{
    public function handle(Rule $rule, int $userId, bool $overwrite): void
    {
        $probe = new ApplyRuleRetroactively($rule->id, $userId, $overwrite);
        $lock = Cache::lock(UniqueLock::getKey($probe));

        if (! $lock->get()) {
            throw new RuleApplyInProgress;
        }

        $lock->release();

        ApplyRuleRetroactively::dispatch($rule->id, $userId, $overwrite);
    }
}
