<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncAccounts;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Banking\Support\ItemRefresher;
use App\Domain\Notifications\Notifications\ConnectionNeedsReauthNotification;
use App\Domain\Notifications\Support\NotificationDeduper;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        ItemRefresher $itemRefresher,
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

        UserContext::run($user, fn () => $this->sync($provider, $accountMapper, $itemRefresher, $syncAccounts, $syncBills, $syncTransactions));
    }

    private function sync(
        BankProvider $provider,
        AccountMapper $accountMapper,
        ItemRefresher $itemRefresher,
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
            $item = $itemRefresher->refreshAndWait($provider, $connection, $item, $syncStartedAt);

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
            // Última tentativa: resolve aqui mesmo (grava o erro, sob a
            // mesma proteção de writeStatus()) em vez de deixar o Laravel
            // "estourar" o job — failed() só existe para exceção/timeout
            // inesperado, não para esta falha transitória já identificada.
            if ($this->attempts() >= $this->tries) {
                $this->writeStatus(ConnectionStatus::Error, 'Não foi possível falar com o banco. Tentaremos de novo.');

                return;
            }

            // Transitória: solta o job de volta na fila com a espera que o
            // próprio provedor pediu (Retry-After de um 429), quando
            // informada — mais precisa do que o backoff fixo padrão. Nunca
            // mais que 15 minutos: um Retry-After absurdamente alto não
            // pode travar o retry além do que $backoff já prevê no pior caso.
            $retryAfter = $e->retryAfter !== null ? min($e->retryAfter, 900) : null;

            if ($retryAfter !== null) {
                $this->release($retryAfter);

                return;
            }

            throw $e;
        }

        $this->writeStatus(ConnectionStatus::Active, null, $syncStartedAt);
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
        // last_synced_at da conexão é o normal; sem ele (não deveria
        // acontecer, já que provider_history_synced_at desta conta implica
        // que algum sync da conexão já terminou — mas por segurança), cai
        // para o próprio instante do histórico desta conta, melhor
        // estimativa do que "agora", que traria praticamente nada.
        $createdAtFrom = $usesFullHistory
            ? null
            : ($connection->last_synced_at ?? $fresh->provider_history_synced_at ?? CarbonImmutable::now())->subDays(14);

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

    private function messageFor(ProviderRequestFailed $e): string
    {
        return $e->status === 404
            ? 'A conexão não existe mais no banco. Conecte de novo.'
            : 'O banco recusou a sincronização.';
    }

    /**
     * Trava e lê a conexão no início do job, e aproveita a mesma trava para
     * registrar `settings.sync_meta` (quando o status achado já deixa a
     * sincronização seguir): o status de partida e o instante em que esta
     * tentativa começou. failed() — que pode rodar bem depois, numa
     * instância recém-deserializada que nunca passou por aqui — lê essas
     * duas informações de volta do banco para decidir se ainda vale gravar
     * um erro (ver writeStatus() e failed()).
     */
    private function lockedConnection(): ?BankConnection
    {
        return DB::transaction(function () {
            $locked = BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, [ConnectionStatus::Active, ConnectionStatus::Error], true)) {
                return $locked;
            }

            $settings = $locked->settings ?? [];
            $settings['sync_meta'] = [
                'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
                'start_status' => $locked->status->value,
            ];
            $locked->update(['settings' => $settings]);

            return $locked;
        });
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
     * descartado para não regredir o mais novo. Nunca sobrescreve
     * `needs_reauth`/`pending_link` com `error`: um destino mais específico
     * decidido por outra coisa (reconexão, desconexão) vale mais do que "não
     * deu pra falar com o banco".
     *
     * failed() roda numa instância recém-deserializada do payload original
     * do job (de antes do primeiro handle()): $this->startStatus nunca foi
     * de fato setado ali — por isso tem sua própria lógica, lendo
     * settings.sync_meta direto do banco em vez desta propriedade.
     */
    private function writeStatus(ConnectionStatus $status, ?string $error, ?CarbonImmutable $syncedAt = null): void
    {
        $startStatus = $this->startStatus;

        DB::transaction(function () use ($startStatus, $status, $error, $syncedAt) {
            $locked = BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($status === ConnectionStatus::Error
                && in_array($locked->status, [ConnectionStatus::NeedsReauth, ConnectionStatus::PendingLink], true)) {
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

            $wasNeedsReauth = $locked->status === ConnectionStatus::NeedsReauth;

            $locked->update($attributes);

            if ($status === ConnectionStatus::NeedsReauth && ! $wasNeedsReauth) {
                $this->notifyNeedsReauth($locked);
            }
        });
    }

    /**
     * Busca o usuário pelo dono da própria conexão (não Auth::user()): esta
     * notificação é só um efeito colateral de writeStatus() ter gravado
     * needs_reauth, e não deve depender do guard continuar com o mesmo
     * usuário ativo até aqui. NotificationDeduper evita duplicar caso
     * writeStatus() seja chamado de novo com o mesmo resultado antes do
     * estado em memória refletir a mudança.
     */
    private function notifyNeedsReauth(BankConnection $connection): void
    {
        $user = User::query()->find($connection->user_id);

        if ($user === null) {
            return;
        }

        app(NotificationDeduper::class)->send($user, new ConnectionNeedsReauthNotification(
            $connection->id,
            $connection->institution_name,
            CarbonImmutable::today()->toDateString(),
        ));
    }

    /**
     * Só para exceção inesperada ou timeout (o job matado por estourar
     * $timeout nunca passa pelo catch(ProviderUnavailable) de sync() —
     * Laravel chama failed() direto): marca a conexão com erro e loga.
     * ProviderAuthFailed/ProviderRequestFailed/ProviderUnavailable (esta
     * última já na última tentativa) nunca chegam aqui — sync() já resolve
     * os três sem deixar a exceção escapar.
     *
     * Lê settings.sync_meta (gravado por lockedConnection() na tentativa que
     * está terminando agora) direto do banco, porque esta é uma instância
     * nova, sem $this->startStatus: pula a gravação quando a conexão já
     * está `needs_reauth`/`pending_link` (outra coisa já decidiu o destino
     * dela) ou quando ela está `active` e ficou assim depois que esta
     * tentativa começou (reconectada, ou já sincronizada de novo com
     * sucesso) — não regride um estado mais novo e melhor com um erro de
     * uma tentativa velha.
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

            DB::transaction(function () {
                $locked = BankConnection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

                if ($locked === null
                    || in_array($locked->status, [ConnectionStatus::NeedsReauth, ConnectionStatus::PendingLink], true)) {
                    return;
                }

                if ($locked->status === ConnectionStatus::Active && $this->supersededByNewerSync($locked)) {
                    return;
                }

                $locked->update(['status' => ConnectionStatus::Error, 'last_error' => 'Não foi possível falar com o banco. Tentaremos de novo.']);
            });
        });
    }

    /**
     * @return array{sync_started_at: ?CarbonImmutable, start_status: ?ConnectionStatus}
     */
    private function syncMeta(BankConnection $connection): array
    {
        $meta = $connection->settings['sync_meta'] ?? null;

        if (! is_array($meta)) {
            return ['sync_started_at' => null, 'start_status' => null];
        }

        $startedAt = $meta['sync_started_at'] ?? null;
        $startStatus = $meta['start_status'] ?? null;

        return [
            'sync_started_at' => is_string($startedAt) ? CarbonImmutable::parse($startedAt) : null,
            'start_status' => is_string($startStatus) ? ConnectionStatus::tryFrom($startStatus) : null,
        ];
    }

    /**
     * A conexão já está `active` agora, mas settings.sync_meta (gravado por
     * lockedConnection() quando esta tentativa começou) mostra que ela NÃO
     * estava `active` no início, ou que já tem um sync mais recente
     * (last_synced_at depois de sync_started_at) — nos dois casos, outra
     * execução (reconexão, ou um sync mais novo que conseguiu terminar) já
     * deixou a conexão num estado melhor do que o erro que esta tentativa
     * traria. Sem sync_meta nenhum (não passou por lockedConnection() nesta
     * execução — ex.: o job nem chegou a rodar handle()), não há indício de
     * regressão: grava o erro normalmente.
     */
    private function supersededByNewerSync(BankConnection $connection): bool
    {
        ['sync_started_at' => $syncStartedAt, 'start_status' => $startStatus] = $this->syncMeta($connection);

        if ($startStatus === null) {
            return false;
        }

        if ($startStatus !== ConnectionStatus::Active) {
            return true;
        }

        return $syncStartedAt !== null
            && $connection->last_synced_at !== null
            && $connection->last_synced_at->greaterThan($syncStartedAt);
    }
}
