<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\ConnectionNeedsReauth;
use App\Domain\Banking\Errors\ConnectionNotLinked;
use App\Domain\Banking\Errors\ConnectionSyncInProgress;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;

/**
 * Despacha SyncConnection, mas primeiro confere se já existe uma
 * sincronização desta conexão em voo. Sem essa conferência, um segundo
 * POST /bank-connections/{connection}/sync enquanto o primeiro job ainda
 * roda cairia no lock único do job (ShouldBeUnique) e seria descartado em
 * silêncio — a API responderia 202 mesmo sem nada ter sido de fato
 * enfileirado (mesmo problema resolvido por
 * App\Domain\Rules\Actions\QueueRuleApplication; ver o comentário lá para
 * o porquê de usar Illuminate\Bus\UniqueLock::getKey() em vez de montar a
 * chave do lock à mão).
 *
 * $trigger vem de quem chama (ver App\Domain\Banking\Enums\SyncTrigger) e
 * só importa se o job de fato chegar a rodar — vira o `trigger` do histórico
 * de sincronização (App\Domain\Banking\Models\BankSyncRun).
 */
final class QueueConnectionSync
{
    public function handle(BankConnection $connection, SyncTrigger $trigger): void
    {
        if ($connection->status === ConnectionStatus::PendingLink) {
            throw new ConnectionNotLinked;
        }

        if ($connection->status === ConnectionStatus::NeedsReauth) {
            throw new ConnectionNeedsReauth;
        }

        $probe = new SyncConnection($connection->id, $trigger);
        $lock = Cache::lock(UniqueLock::getKey($probe));

        if (! $lock->get()) {
            throw new ConnectionSyncInProgress;
        }

        $lock->release();

        SyncConnection::dispatch($connection->id, $trigger);
    }
}
