<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncAccounts;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Sincroniza uma conexão bancária (item, contas, saldos, faturas,
 * transações, limpeza de pendentes antigos). Único por conexão: duas
 * sincronizações da mesma conexão em paralelo não fazem sentido —
 * App\Domain\Banking\Actions\QueueConnectionSync confere o lock antes de
 * despachar (mesmo padrão de App\Domain\Rules\Actions\QueueRuleApplication),
 * para quem chama duas vezes em seguida receber 409 em vez de um 202 que
 * não enfileirou nada de verdade.
 *
 * $timeout fica abaixo de DB_QUEUE_RETRY_AFTER (660s, ver .env.example):
 * maior que isso, o worker da fila "database" acha que o job travou e o
 * libera de novo antes do handle() atual terminar, rodando os dois ao
 * mesmo tempo. uniqueFor() (30 min) é bem maior que o pior caso de
 * $timeout × $tries, para o lock não vencer sozinho enquanto uma tentativa
 * de retry ainda está de pé.
 *
 * A conexão é travada (lockForUpdate) só no início (para ler um status
 * consistente) e no fim (para gravar o resultado) — nunca durante as
 * chamadas de rede ao provedor, que podem demorar.
 */
final class SyncConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(
        BankProvider $provider,
        AccountMapper $accountMapper,
        SyncAccounts $syncAccounts,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
    ): void {
        // withoutGlobalScopes: não há usuário autenticado ainda (é
        // justamente o que este find serve para descobrir).
        $connection = BankConnection::query()->withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $user = User::query()->find($connection->user_id);

        if ($user === null) {
            return;
        }

        UserContext::run($user, fn () => $this->sync($provider, $accountMapper, $syncAccounts, $syncBills, $syncTransactions));
    }

    private function sync(
        BankProvider $provider,
        AccountMapper $accountMapper,
        SyncAccounts $syncAccounts,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
    ): void {
        $connection = $this->lockedConnection();

        if ($connection === null || ! in_array($connection->status, [ConnectionStatus::Active, ConnectionStatus::Error], true)) {
            return;
        }

        $originalStatus = $connection->status;
        $isFirstSync = $connection->last_synced_at === null;
        $syncStartedAt = CarbonImmutable::now();

        try {
            $item = $provider->item($connection->external_id);

            if ($item->lastUpdatedAt !== null && $item->lastUpdatedAt->lessThanOrEqualTo($syncStartedAt->subHours(20))) {
                $provider->refreshItem($connection->external_id);
                $item = $this->waitForUpdate($provider, $connection->external_id);
            }

            if ($item->needsReauth()) {
                $this->writeStatus(
                    $originalStatus,
                    ConnectionStatus::NeedsReauth,
                    $item->errorMessage ?? 'O banco pediu para reconectar.',
                );

                return;
            }
            // OUTDATED ou ainda UPDATING depois do tempo máximo de espera:
            // segue com o que o banco já tem agora, em vez de falhar o sync.

            $providerAccounts = $provider->accounts($connection->external_id);
            $syncAccounts->handle($connection, $providerAccounts);

            foreach ($connection->accounts()->get() as $account) {
                $this->syncAccount($account, $connection, $isFirstSync, $syncStartedAt, $provider, $accountMapper, $syncBills, $syncTransactions);
            }
        } catch (ProviderAuthFailed) {
            // Falha permanente (credenciais do servidor recusadas): não
            // insiste — nenhuma tentativa seguinte mudaria o resultado.
            // Sem rethrow: o job termina "com sucesso" para a fila (sem
            // retry), o estado de erro é que carrega a notícia.
            $this->writeStatus($originalStatus, ConnectionStatus::Error, 'Credenciais do servidor recusadas pela Pluggy.');

            return;
        }

        $this->writeStatus($originalStatus, ConnectionStatus::Active, null, $syncStartedAt);
    }

    private function syncAccount(
        Account $account,
        BankConnection $connection,
        bool $isFirstSync,
        CarbonImmutable $syncStartedAt,
        BankProvider $provider,
        AccountMapper $accountMapper,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
    ): void {
        if ($account->external_id === null) {
            return;
        }

        if ($account->isCreditCard()) {
            $bills = $provider->bills($account->external_id);
            $syncBills->handle($account, $bills);
        }

        $dateFrom = $isFirstSync ? $this->firstSyncFloor($account) : null;
        $createdAtFrom = $isFirstSync ? null : $connection->last_synced_at?->subDays(14);

        $transactions = $provider->transactions($account->external_id, $account->isCreditCard(), $dateFrom, $createdAtFrom);
        $syncTransactions->handle($account, $transactions, $syncStartedAt);

        $accountMapper->settleOpeningBalance($account);
    }

    /**
     * Nunca mais de 365 dias (o máximo que a Pluggy guarda); nunca antes de
     * provider_sync_from (o que é anterior já está no saldo inicial do
     * usuário, ver AccountMapper::linkExisting()) — o mais recente dos dois
     * dias (o "piso" mínimo de histórico a pedir).
     */
    private function firstSyncFloor(Account $account): CarbonImmutable
    {
        $floor = CarbonImmutable::now()->subDays(365);

        return $account->provider_sync_from !== null ? $floor->max($account->provider_sync_from) : $floor;
    }

    private function waitForUpdate(BankProvider $provider, string $itemId): ProviderItem
    {
        $item = $provider->item($itemId);

        for ($attempt = 0; $attempt < 30 && $item->isUpdating(); $attempt++) {
            Sleep::for(3)->seconds();
            $item = $provider->item($itemId);
        }

        return $item;
    }

    private function lockedConnection(): ?BankConnection
    {
        return DB::transaction(
            fn () => BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first(),
        );
    }

    /**
     * $originalStatus é o status lido no início deste job (lockedConnection()),
     * antes de qualquer chamada de rede — serve só para reconhecer uma
     * reconexão concorrente: se a conexão já estava `active` quando o job
     * começou, uma gravação não-active ao final é o resultado normal deste
     * próprio sync (ex.: o banco pediu reconexão agora), e é aplicada. Só
     * quando a conexão NÃO estava `active` no início mas JÁ está `active`
     * agora (outra execução — reconexão do usuário ou outro job — terminou
     * no meio do caminho) é que o resultado desta execução, calculado com
     * um estado mais antigo, é descartado para não regredir o mais novo.
     *
     * $originalStatus null (usado por failed(), que não participa da mesma
     * execução que leu o status original): grava sempre, sem essa proteção
     * — esgotadas as 3 tentativas com espera crescente, é o melhor sinal
     * que se tem.
     */
    private function writeStatus(?ConnectionStatus $originalStatus, ConnectionStatus $status, ?string $error, ?CarbonImmutable $syncedAt = null): void
    {
        DB::transaction(function () use ($originalStatus, $status, $error, $syncedAt) {
            $locked = BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($originalStatus !== null
                && $status !== ConnectionStatus::Active
                && $originalStatus !== ConnectionStatus::Active
                && $locked->status === ConnectionStatus::Active) {
                return;
            }

            $attributes = ['status' => $status, 'last_error' => $error];

            if ($syncedAt !== null) {
                $attributes['last_synced_at'] = $syncedAt;
            }

            $locked->update($attributes);
        });
    }

    /**
     * Esgotadas as tentativas (erro transitório persistente — ProviderUnavailable
     * ou qualquer outra falha inesperada): marca a conexão com erro e loga.
     * ProviderAuthFailed nunca chega aqui — handle() já resolve sem
     * deixar a exceção escapar (sem retry).
     */
    public function failed(Throwable $exception): void
    {
        $connection = BankConnection::query()->withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $user = User::query()->find($connection->user_id);

        if ($user === null) {
            return;
        }

        UserContext::run($user, function () use ($exception): void {
            Log::error('Pluggy: falha ao sincronizar a conexão bancária depois de todas as tentativas.', [
                'connection_id' => $this->connectionId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->writeStatus(null, ConnectionStatus::Error, 'Não foi possível falar com o banco. Tentaremos de novo.');
        });
    }
}
