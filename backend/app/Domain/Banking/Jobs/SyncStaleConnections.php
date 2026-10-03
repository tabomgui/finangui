<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Models\BankConnection;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Agendador (routes/console.php, a cada 6h): despacha
 * App\Domain\Banking\Jobs\SyncConnection para cada conexão `active` vencida
 * (nunca sincronizada, ou sincronizada há 4h ou mais). Roda por usuário,
 * dentro de UserContext, para o escopo por usuário continuar valendo (mesmo
 * padrão de App\Domain\Cards\Jobs\PostDueInstallments).
 */
final class SyncStaleConnections implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $threshold = CarbonImmutable::now()->subHours(4)->toDateTimeString();
        $stale = fn (Builder $query) => $query
            ->where('status', ConnectionStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $threshold));

        $owners = $stale(BankConnection::query()->withoutGlobalScopes())->select('user_id');

        // eachById: owners encolhe a cada usuário processado (os jobs
        // despachados não terminam na hora, mas o próprio despacho já marca
        // a conexão como "em voo" via ShouldBeUnique — não há razão para
        // reconsiderar o mesmo usuário na mesma rodada); paginação por
        // offset (each/chunk) pularia donos depois do primeiro lote.
        // eachById pagina pelo id do User, que não encolhe.
        User::query()->whereIn('id', $owners)->eachById(
            fn (User $user) => UserContext::run($user, function () use ($stale): void {
                /** @var list<BankConnection> $connections */
                $connections = $stale(BankConnection::query())->get()->all();

                foreach ($connections as $connection) {
                    SyncConnection::dispatch($connection->id);
                }
            }),
        );
    }
}
