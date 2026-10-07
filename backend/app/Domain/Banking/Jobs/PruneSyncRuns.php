<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Models\BankSyncRun;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Retenção do histórico de sincronização (routes/console.php, diário): apaga
 * App\Domain\Banking\Models\BankSyncRun com mais de RETENTION_DAYS dias,
 * mantendo sempre os KEEP_PER_CONNECTION mais recentes de cada conexão —
 * mesmo uma conexão sincronizada o dia inteiro nunca perde o histórico mais
 * recente, só o mais antigo. App\Domain\Banking\Models\BankSyncRunItem
 * cascateia pela FK (run_id) — não precisa de exclusão própria.
 *
 * Também fecha qualquer run ainda `running` (runs presas) cujo
 * `started_at` já passou de SyncConnection::UNIQUE_FOR_SECONDS mais uma
 * margem de segurança: o próximo dispatch da mesma conexão já faria isso
 * (SyncConnection::closeStuckRuns()), mas uma conexão que nunca mais
 * sincroniza (ex.: desconectada, ou com erro permanente que ninguém
 * resolve) nunca teria essa chance — este job diário garante que uma run
 * presa não fique `running` para sempre nesse caso.
 *
 * Por usuário, dentro de UserContext (mesmo padrão de
 * App\Domain\Banking\Jobs\SyncStaleConnections): cada conexão só é
 * considerada depois de um sync terminar com uma run própria, então nunca há
 * "dono" sem usuário correspondente.
 */
final class PruneSyncRuns implements ShouldQueue
{
    use Queueable;

    private const RETENTION_DAYS = 90;

    private const KEEP_PER_CONNECTION = 20;

    /** Margem de segurança acima de SyncConnection::UNIQUE_FOR_SECONDS antes de considerar uma run `running` presa. */
    private const STUCK_RUN_MARGIN_SECONDS = 600;

    public function handle(): void
    {
        $threshold = CarbonImmutable::now()->subDays(self::RETENTION_DAYS);
        $stuckThreshold = CarbonImmutable::now()->subSeconds(SyncConnection::UNIQUE_FOR_SECONDS + self::STUCK_RUN_MARGIN_SECONDS);

        $userIds = BankSyncRun::query()->withoutGlobalScopes()->distinct()->pluck('user_id');

        User::query()->whereIn('id', $userIds)->eachById(
            fn (User $user) => UserContext::run($user, function () use ($threshold, $stuckThreshold): void {
                $this->closeStuckRunningRuns($stuckThreshold);

                $connectionIds = BankSyncRun::query()->distinct()->pluck('connection_id');

                foreach ($connectionIds as $connectionId) {
                    $this->pruneConnection($connectionId, $threshold);
                }
            }),
        );
    }

    private function closeStuckRunningRuns(CarbonImmutable $stuckThreshold): void
    {
        BankSyncRun::query()
            ->where('status', SyncRunStatus::Running)
            ->where('started_at', '<', $stuckThreshold)
            ->update([
                'status' => SyncRunStatus::Error,
                'finished_at' => CarbonImmutable::now(),
                'error' => 'Sincronização interrompida',
            ]);
    }

    private function pruneConnection(int $connectionId, CarbonImmutable $threshold): void
    {
        $keepIds = BankSyncRun::query()
            ->where('connection_id', $connectionId)
            ->orderByDesc('id')
            ->limit(self::KEEP_PER_CONNECTION)
            ->pluck('id');

        BankSyncRun::query()
            ->where('connection_id', $connectionId)
            ->where('started_at', '<', $threshold)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
