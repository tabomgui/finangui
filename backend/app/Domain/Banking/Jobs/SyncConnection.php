<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncAccounts;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
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
 * mesmo tempo. uniqueFor() (1h) é bem maior que o pior caso de
 * $timeout × $tries (600 × 3 = 1800s), para o lock não vencer sozinho
 * enquanto uma tentativa de retry ainda está de pé.
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

    /**
     * Status da conexão no início desta execução (lockedConnection()),
     * antes de qualquer chamada de rede — ver writeStatus(). Não
     * sobrevive a um retry de verdade (cada tentativa deserializa o job a
     * partir do payload original, de antes do primeiro handle() rodar);
     * dentro da mesma execução, porém, é o bastante para writeStatus()
     * reconhecer uma reconexão concorrente sem precisar passar o valor por
     * parâmetro em cada chamada.
     */
    private ?ConnectionStatus $startStatus = null;

    public function __construct(
        public int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return 3600;
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

        $this->startStatus = $connection->status;
        $syncStartedAt = CarbonImmutable::now();

        try {
            $item = $provider->item($connection->external_id);
            $item = $this->refreshAndWait($provider, $connection, $item, $syncStartedAt);

            if ($item->needsReauth()) {
                $this->writeStatus(
                    ConnectionStatus::NeedsReauth,
                    $item->errorMessage ?? 'O banco pediu para reconectar.',
                );

                return;
            }
            // OUTDATED ou ainda UPDATING depois do tempo máximo de espera:
            // segue com o que o banco já tem agora, em vez de falhar o sync.

            $categoriesById = $this->categoriesById($provider);
            $providerAccounts = $provider->accounts($connection->external_id);
            $syncAccounts->handle($connection, $providerAccounts);

            /** @var list<string> $providerExternalIds */
            $providerExternalIds = array_map(fn (ProviderAccount $a): string => $a->id, $providerAccounts);

            foreach ($connection->accounts()->get() as $account) {
                $this->syncAccount(
                    $account,
                    $connection,
                    $providerExternalIds,
                    $syncStartedAt,
                    $provider,
                    $accountMapper,
                    $syncBills,
                    $syncTransactions,
                    $categoriesById,
                );
            }
        } catch (ProviderAuthFailed) {
            // Falha permanente (credenciais do servidor recusadas): não
            // insiste — nenhuma tentativa seguinte mudaria o resultado.
            // Sem rethrow: o job termina "com sucesso" para a fila (sem
            // retry), o estado de erro é que carrega a notícia.
            $this->writeStatus(ConnectionStatus::Error, 'Credenciais do servidor recusadas pela Pluggy.');

            return;
        } catch (ProviderRequestFailed $e) {
            // Também permanente (4xx inesperado fora da autenticação): sem
            // rethrow, mesmo raciocínio do catch acima.
            $this->writeStatus(ConnectionStatus::Error, $this->messageFor($e));

            return;
        } catch (ProviderUnavailable $e) {
            // Transitória: solta o job de volta na fila com a espera que o
            // próprio provedor pediu (Retry-After de um 429), quando
            // informada — mais precisa do que o backoff fixo padrão.
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        $this->writeStatus(ConnectionStatus::Active, null, $syncStartedAt);
    }

    /**
     * Item já em UPDATING (de um refresh anterior, deste sync ou de fora)
     * só espera, sem pedir outro; sem lastUpdatedAt (nunca atualizado) não
     * há como saber se está velho, então também só espera (nunca refresca
     * "no escuro" — refreshItem tem limite de uso pela Pluggy). Pede
     * atualização só quando lastUpdatedAt existe e já tem 20h ou mais.
     * Falha do provedor ao pedir ou esperar a atualização (indisponível ou
     * recusado) não derruba o sync inteiro: loga e segue com o último item
     * que conseguiu buscar.
     */
    private function refreshAndWait(BankProvider $provider, BankConnection $connection, ProviderItem $item, CarbonImmutable $syncStartedAt): ProviderItem
    {
        $shouldRefresh = ! $item->isUpdating()
            && $item->lastUpdatedAt !== null
            && $item->lastUpdatedAt->lessThanOrEqualTo($syncStartedAt->subHours(20));

        if ($shouldRefresh) {
            try {
                $provider->refreshItem($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                $this->logProviderWarning('Pluggy: falha ao pedir atualização do item; seguindo com o item já buscado.', $connection, $e);
            }
        }

        return $this->waitForUpdate($provider, $connection, $item);
    }

    /**
     * @param  list<string>  $providerExternalIds
     * @param  array<string, ProviderCategory>  $categoriesById
     */
    private function syncAccount(
        Account $account,
        BankConnection $connection,
        array $providerExternalIds,
        CarbonImmutable $syncStartedAt,
        BankProvider $provider,
        AccountMapper $accountMapper,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
        array $categoriesById,
    ): void {
        if ($account->external_id === null) {
            return;
        }

        if (! in_array($account->external_id, $providerExternalIds, true)) {
            // A conta sumiu da lista do banco neste sync (ex.: encerrada no
            // banco) — nada de novo a buscar para ela; fica como está até
            // o usuário decidir o que fazer.
            Log::info('Pluggy: conta vinculada não apareceu na lista de contas do banco neste sync; pulando.', [
                'connection_id' => $connection->id, 'account_id' => $account->id,
            ]);

            return;
        }

        // Relido: entre o início deste job e aqui, a conta pode ter sido
        // desvinculada ou movida para outra conexão (ex.: desconectar
        // enquanto o sync roda).
        $fresh = Account::query()->find($account->id);

        if ($fresh === null || $fresh->connection_id !== $connection->id) {
            Log::info('Pluggy: conta não pertence mais a esta conexão; pulando.', [
                'connection_id' => $connection->id, 'account_id' => $account->id,
            ]);

            return;
        }

        if ($fresh->isCreditCard()) {
            $bills = $provider->bills($fresh->external_id);
            $syncBills->handle($fresh, $bills);
        }

        $usesFullHistory = $fresh->provider_history_synced_at === null;
        $dateFrom = $usesFullHistory ? $this->firstSyncFloor($fresh) : null;
        $createdAtFrom = $usesFullHistory
            ? null
            : ($connection->last_synced_at ?? CarbonImmutable::now())->subDays(14);

        $transactions = $provider->transactions($fresh->external_id, $fresh->isCreditCard(), $dateFrom, $createdAtFrom);
        $syncTransactions->handle($fresh, $transactions, $syncStartedAt, $categoriesById);

        if ($usesFullHistory) {
            $accountMapper->markHistorySynced($fresh);
        }

        $accountMapper->settleOpeningBalance($fresh);
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

    /**
     * @return array<string, ProviderCategory>
     */
    private function categoriesById(BankProvider $provider): array
    {
        $byId = [];

        foreach ($provider->categories() as $category) {
            $byId[$category->id] = $category;
        }

        return $byId;
    }

    private function waitForUpdate(BankProvider $provider, BankConnection $connection, ProviderItem $item): ProviderItem
    {
        for ($attempt = 0; $attempt < 30 && $item->isUpdating(); $attempt++) {
            Sleep::for(3)->seconds();

            try {
                $item = $provider->item($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                $this->logProviderWarning('Pluggy: falha ao consultar o item enquanto esperava a atualização; seguindo com o último item buscado.', $connection, $e);

                break;
            }
        }

        return $item;
    }

    private function logProviderWarning(string $message, BankConnection $connection, Throwable $e): void
    {
        Log::warning($message, [
            'connection_id' => $connection->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    private function messageFor(ProviderRequestFailed $e): string
    {
        return $e->status === 404
            ? 'A conexão não existe mais no banco. Conecte de novo.'
            : 'O banco recusou a sincronização.';
    }

    private function lockedConnection(): ?BankConnection
    {
        return DB::transaction(
            fn () => BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first(),
        );
    }

    /**
     * $this->startStatus é o status lido no início deste job
     * (lockedConnection()), antes de qualquer chamada de rede — serve só
     * para reconhecer uma reconexão concorrente: se a conexão já estava
     * `active` quando o job começou, uma gravação não-active ao final é o
     * resultado normal deste próprio sync (ex.: o banco pediu reconexão
     * agora), e é aplicada. Só quando a conexão NÃO estava `active` no
     * início mas JÁ está `active` agora (outra execução — reconexão do
     * usuário ou outro job — terminou no meio do caminho) é que o
     * resultado desta execução, calculado com um estado mais antigo, é
     * descartado para não regredir o mais novo.
     *
     * failed() roda numa instância recém-deserializada do payload original
     * do job (de antes do primeiro handle()): $this->startStatus nunca foi
     * de fato setado ali, então essa proteção não se aplica — grava sempre,
     * esgotadas as tentativas é o melhor sinal que se tem.
     */
    private function writeStatus(ConnectionStatus $status, ?string $error, ?CarbonImmutable $syncedAt = null): void
    {
        $startStatus = $this->startStatus;

        DB::transaction(function () use ($startStatus, $status, $error, $syncedAt) {
            $locked = BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($startStatus !== null
                && $status !== ConnectionStatus::Active
                && $startStatus !== ConnectionStatus::Active
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
     * sem Retry-After, ou qualquer outra falha inesperada): marca a conexão
     * com erro e loga. ProviderAuthFailed/ProviderRequestFailed nunca chegam
     * aqui — handle() já resolve os dois sem deixar a exceção escapar (sem
     * retry).
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

            $this->writeStatus(ConnectionStatus::Error, 'Não foi possível falar com o banco. Tentaremos de novo.');
        });
    }
}
