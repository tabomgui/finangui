<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\ReconcileCardPayments;
use App\Domain\Banking\Actions\SyncAccounts;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Models\BankSyncRun;
use App\Domain\Banking\Models\BankSyncRunItem;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Banking\Support\ItemRefresher;
use App\Domain\Notifications\Notifications\ConnectionNeedsReauthNotification;
use App\Domain\Notifications\Support\NotificationDeduper;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sincroniza uma conexão bancária (item, contas, saldos, faturas,
 * transações, limpeza de pendentes antigos, reconciliação de pagamentos de
 * fatura — ver App\Domain\Banking\Actions\ReconcileCardPayments, chamada com
 * os mesmos bills já buscados para App\Domain\Banking\Actions\SyncBills).
 * Único por conexão: duas
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

    /**
     * Syncs seguintes: janela re-sincronizada por data, além de
     * createdAtFrom — ver recentWindowTransactions().
     */
    private const RESYNC_WINDOW_DAYS = 40;

    /** Itens de BankSyncRun com snapshot; acima disso, só a contagem (added_count). */
    private const MAX_RUN_ITEMS = 500;

    /**
     * uniqueFor() (1h) — público para App\Domain\Banking\Jobs\PruneSyncRuns
     * calcular o limiar de "run presa" (started_at mais antigo que isso, com
     * uma margem) sem duplicar o número.
     */
    public const UNIQUE_FOR_SECONDS = 3600;

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

    /**
     * Linha do histórico de sincronização desta execução — criada (ou
     * reaproveitada de um retry, ver startOrResumeRun()) em lockedConnection(),
     * sob a mesma trava; permanece null quando a conexão nem chega a ser
     * elegível para sincronizar (ver o comentário de ShouldBeUnique na classe:
     * uma run só existe quando o job de fato começou). `added_count`/
     * `updated_count`/`bills_count`/os itens são gravados direto no banco a
     * cada conta sincronizada (ver persistAccountProgress()), não acumulados
     * aqui em memória — sem isso, um crash no meio do job perderia o
     * progresso das contas já sincronizadas nesta tentativa. Os contadores
     * abaixo (reconciliação, aviso, refresh) são a exceção: só fazem sentido
     * como resultado do job inteiro, então ficam em memória e são gravados
     * de uma vez em finishRun().
     */
    private ?BankSyncRun $syncRun = null;

    /** @var list<string> */
    private array $warnings = [];

    private int $paymentsRecognized = 0;

    private int $duplicatesIgnored = 0;

    private int $transfersLinked = 0;

    private bool $refreshRequested = false;

    private ?CarbonImmutable $providerUpdatedAt = null;

    /**
     * $trigger e $jobUuid têm default só para não quebrar quem despacha/
     * instancia este job sem se importar com a origem (ex.: testes antigos,
     * ou o probe de App\Domain\Banking\Actions\QueueConnectionSync, que
     * nunca chega a chamar handle()); todo dispatch "de verdade" informa o
     * trigger certo (ver App\Domain\Banking\Jobs\SyncStaleConnections e
     * App\Domain\Banking\Actions\QueueConnectionSync) — fica registrado em
     * App\Domain\Banking\Models\BankSyncRun e também decide o limiar de
     * "item velho" em App\Domain\Banking\Support\ItemRefresher (ver trigger()
     * — acessor defensivo contra um job serializado antes deste deploy).
     *
     * $jobUuid identifica esta execução lógica (o mesmo em todo retry —
     * release() ou o retry automático do Laravel —, porque é gerado uma
     * única vez aqui no construtor e sobrevive à serialização/deserialização
     * do job entre tentativas; diferente em qualquer dispatch novo, mesmo
     * desta mesma conexão) — ver startOrResumeRun()/jobUuid().
     */
    public function __construct(
        public int $connectionId,
        public SyncTrigger $trigger = SyncTrigger::Scheduled,
        public string $jobUuid = '',
    ) {
        if ($this->jobUuid === '') {
            $this->jobUuid = (string) Str::uuid();
        }
    }

    /**
     * Acessor defensivo para $trigger: um job enfileirado antes deste deploy
     * foi serializado sem esta propriedade (ela não existia ainda na
     * classe) — unserialize() nunca preenche o default da classe para uma
     * propriedade ausente no payload antigo, então ler $this->trigger direto
     * nesse caso lançaria "must not be accessed before initialization".
     * Usado em todo lugar interno que precisa do trigger; a propriedade
     * pública continua existindo, para quem despacha/inspeciona um job
     * recém-criado (nunca deserializado de antes deste deploy).
     */
    private function trigger(): SyncTrigger
    {
        // @phpstan-ignore isset.property (falso positivo: phpstan não modela unserialize() de um payload antigo sem esta propriedade — isset() é seguro e necessário aqui, ver o docblock acima)
        return isset($this->trigger) ? $this->trigger : SyncTrigger::Scheduled;
    }

    /**
     * Mesmo raciocínio de trigger(): um job de antes deste deploy não tem
     * $jobUuid serializado. Sem um valor estável vindo do payload original,
     * gera um novo (memoizado nesta instância) — na prática, trata um job
     * assim como um dispatch novo em vez de tentar (e falhar) casar com uma
     * run antiga.
     */
    private function jobUuid(): string
    {
        // @phpstan-ignore isset.property (falso positivo: mesmo motivo de trigger() acima)
        if (! isset($this->jobUuid) || $this->jobUuid === '') {
            $this->jobUuid = (string) Str::uuid();
        }

        return $this->jobUuid;
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function handle(
        BankProviderFactory $providerFactory,
        AccountMapper $accountMapper,
        ItemRefresher $itemRefresher,
        SyncAccounts $syncAccounts,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
        ReconcileCardPayments $reconcileCardPayments,
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

        UserContext::run($user, fn () => $this->sync($providerFactory, $user, $accountMapper, $itemRefresher, $syncAccounts, $syncBills, $syncTransactions, $reconcileCardPayments));
    }

    private function sync(
        BankProviderFactory $providerFactory,
        User $user,
        AccountMapper $accountMapper,
        ItemRefresher $itemRefresher,
        SyncAccounts $syncAccounts,
        SyncBills $syncBills,
        SyncTransactions $syncTransactions,
        ReconcileCardPayments $reconcileCardPayments,
    ): void {
        $connection = $this->lockedConnection();

        if ($connection === null || ! in_array($connection->status, [ConnectionStatus::Active, ConnectionStatus::Error], true)) {
            return;
        }

        $this->startStatus = $connection->status;
        $syncStartedAt = CarbonImmutable::now();
        // Fingerprint da credencial usada por esta tentativa (não a mais
        // recente na hora de gravar o resultado: ver writeStatus()) — só é
        // de fato aplicada na conexão no final, em caso de sucesso, e só
        // quando ela ainda está null (ver App\Domain\Banking\Models\BankConnection).
        $credentialFingerprint = null;

        try {
            // Credenciais do dono da conexão (não um provedor global) — resolvidas
            // dentro do try: sem credenciais válidas cai no
            // catch(BankingDisabled) abaixo em vez de derrubar o job (ver o
            // comentário lá para quando isso acontece de verdade).
            $provider = $providerFactory->for($user);
            $credentialFingerprint = BankCredential::currentFingerprintFor($user);

            $item = $provider->item($connection->external_id);
            $refreshOutcome = $itemRefresher->refreshAndWait($provider, $connection, $item, $syncStartedAt, $this->trigger());
            $item = $refreshOutcome->item;
            // Um refresh pedido mas que falhou (warning preenchido) não
            // conta como "pedimos atualização" do ponto de vista do
            // histórico — é um pedido que não chegou a acontecer de verdade.
            $this->refreshRequested = $refreshOutcome->refreshRequested && $refreshOutcome->warning === null;
            $this->providerUpdatedAt = $item->lastUpdatedAt;

            if ($refreshOutcome->warning !== null) {
                $this->warnings[] = $refreshOutcome->warning;
            }

            if ($item->needsReauth()) {
                // O texto do provedor (livre, pode variar) continua indo
                // para a conexão (last_error, mostrado na tela) e para o
                // log — a run guarda só a mensagem fixa em português, para o
                // histórico de sincronização nunca expor texto arbitrário do
                // banco.
                $providerMessage = $item->errorMessage ?? 'O banco pediu para reconectar.';
                $this->writeStatus(ConnectionStatus::NeedsReauth, $providerMessage);

                Log::info('Pluggy: item pediu reconexão.', [
                    'connection_id' => $connection->id,
                    'provider_message' => $providerMessage,
                ]);

                $this->finishRun(SyncRunStatus::Error, 'O banco pediu para reconectar.');

                return;
            }
            // OUTDATED ou ainda UPDATING depois do tempo máximo de espera:
            // segue com o que o banco já tem agora, em vez de falhar o sync.

            $categoriesById = $this->categoriesById($provider);
            $providerAccounts = $provider->accounts($connection->external_id);
            $syncAccounts->handle($connection, $providerAccounts);

            /** @var list<string> $providerExternalIds */
            $providerExternalIds = array_map(fn (ProviderAccount $a): string => $a->id, $providerAccounts);

            // Faturas de cada cartão sincronizado, guardadas aqui (em vez de reconciliar já
            // dentro de syncAccount()) para a reconciliação de pagamentos rodar só depois que
            // TODAS as contas desta conexão já estiverem sincronizadas: um pagamento cuja perna
            // de débito está numa conta corrente sincronizada DEPOIS do cartão neste mesmo loop
            // (ordem de App\Domain\Banking\Models\BankConnection::accounts(), não garantida)
            // senão nunca acharia a outra perna a tempo de ligar a transferência neste sync —
            // só no próximo.
            /** @var list<array{0: Account, 1: list<ProviderBill>}> $cardsToReconcile */
            $cardsToReconcile = [];

            foreach ($connection->accounts()->get() as $account) {
                $cardSync = $this->syncAccount(
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

                if ($cardSync !== null) {
                    $cardsToReconcile[] = $cardSync;
                }
            }

            foreach ($cardsToReconcile as [$card, $bills]) {
                $reconciliation = $reconcileCardPayments->handle($card, $bills, $this->reconciliationWindowFrom($bills));
                $this->paymentsRecognized += $reconciliation['payments'];
                $this->duplicatesIgnored += $reconciliation['duplicates'];
                $this->transfersLinked += $reconciliation['transfers_linked'];
            }
        } catch (BankingDisabled $e) {
            // Acontece para conexões criadas sob as credenciais globais
            // antigas (variável de ambiente) ou adotadas por um sync
            // anterior sob uma credencial que o usuário removeu depois
            // (permitido quando o fingerprint delas não bate com o da conta
            // atual — ver App\Domain\Banking\Actions\DeleteBankCredentials),
            // até o usuário cadastrar/recadastrar em Configurações; também
            // cobre uma credencial que não descriptografa mais (ver
            // PluggyProviderFactory). Falha permanente: não insiste.
            // $e->getMessage() já é a mensagem voltada ao usuário de BankingDisabled.
            $this->writeStatus(ConnectionStatus::Error, $e->getMessage());
            $this->finishRun(SyncRunStatus::Error, $e->getMessage());

            return;
        } catch (ProviderAuthFailed) {
            // Falha permanente (credenciais recusadas pela Pluggy): não
            // insiste — nenhuma tentativa seguinte mudaria o resultado.
            // Sem rethrow: o job termina "com sucesso" para a fila (sem
            // retry), o estado de erro é que carrega a notícia.
            $message = 'A Pluggy recusou suas credenciais. Atualize em Configurações.';
            $this->writeStatus(ConnectionStatus::Error, $message);
            $this->finishRun(SyncRunStatus::Error, $message);

            return;
        } catch (ProviderRequestFailed $e) {
            // Também permanente (4xx inesperado fora da autenticação): sem
            // rethrow, mesmo raciocínio do catch acima.
            $message = $this->messageFor($e);
            $this->writeStatus(ConnectionStatus::Error, $message);
            $this->finishRun(SyncRunStatus::Error, $message);

            return;
        } catch (ProviderUnavailable $e) {
            // Última tentativa: resolve aqui mesmo (grava o erro, sob a
            // mesma proteção de writeStatus()) em vez de deixar o Laravel
            // "estourar" o job — failed() só existe para exceção/timeout
            // inesperado, não para esta falha transitória já identificada.
            if ($this->attempts() >= $this->tries) {
                $message = 'Não foi possível falar com o banco. Tentaremos de novo.';
                $this->writeStatus(ConnectionStatus::Error, $message);
                $this->finishRun(SyncRunStatus::Error, $message);

                return;
            }

            // Transitória: solta o job de volta na fila com a espera que o
            // próprio provedor pediu (Retry-After de um 429), quando
            // informada — mais precisa do que o backoff fixo padrão. Nunca
            // mais que 15 minutos: um Retry-After absurdamente alto não
            // pode travar o retry além do que $backoff já prevê no pior caso.
            // A run NUNCA é fechada aqui: a próxima tentativa (release() ou
            // o retry automático do rethrow abaixo) reaproveita a mesma linha
            // (ver startOrResumeRun()) — só a tentativa que de fato termina
            // grava o resultado final.
            $retryAfter = $e->retryAfter !== null ? min($e->retryAfter, 900) : null;

            if ($retryAfter !== null) {
                $this->release($retryAfter);

                return;
            }

            throw $e;
        }

        $this->writeStatus(ConnectionStatus::Active, null, $syncStartedAt, $credentialFingerprint);

        if ($this->duplicatesIgnored > 0) {
            // Reconciliação de pagamentos duplicados (ver
            // App\Domain\Banking\Actions\ReconcileCardPayments): vale o
            // aviso porque, embora automático, é algo que o usuário pode
            // querer confirmar na lista de lançamentos da fatura. Pagamentos
            // reconhecidos e transferências ligadas, por outro lado, são o
            // resultado esperado de cada sync — ficam só em `stats`
            // (ver reconcileStatsPayload()), sem gerar aviso.
            $this->warnings[] = "{$this->duplicatesIgnored} pagamento(s) duplicado(s) de fatura foram ignorados automaticamente nesta sincronização.";
        }

        $this->finishRun($this->warnings === [] ? SyncRunStatus::Success : SyncRunStatus::Partial, null);
    }

    /**
     * Sincroniza uma conta e devolve, só para um cartão, o par (conta, faturas) para a
     * reconciliação de pagamentos (ver sync() acima) — nunca reconcilia aqui: isso rodaria antes
     * de outras contas desta conexão (ex.: a conta corrente do débito de um pagamento) ainda
     * terem sido sincronizadas nesta mesma execução.
     *
     * @param  list<string>  $providerExternalIds
     * @param  array<string, ProviderCategory>  $categoriesById
     * @return array{0: Account, 1: list<ProviderBill>}|null
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
    ): ?array {
        if ($account->external_id === null) {
            return null;
        }

        if (! in_array($account->external_id, $providerExternalIds, true)) {
            // A conta sumiu da lista do banco neste sync (ex.: encerrada no
            // banco) — nada de novo a buscar para ela; fica como está até
            // o usuário decidir o que fazer.
            Log::info('Pluggy: conta vinculada não apareceu na lista de contas do banco neste sync; pulando.', [
                'connection_id' => $connection->id, 'account_id' => $account->id,
            ]);

            return null;
        }

        // Relido: entre o início deste job e aqui, a conta pode ter sido
        // desvinculada ou movida para outra conexão (ex.: desconectar
        // enquanto o sync roda).
        $fresh = Account::query()->find($account->id);

        if ($fresh === null || $fresh->connection_id !== $connection->id) {
            Log::info('Pluggy: conta não pertence mais a esta conexão; pulando.', [
                'connection_id' => $connection->id, 'account_id' => $account->id,
            ]);

            return null;
        }

        $bills = [];
        $billsApplied = 0;

        if ($fresh->isCreditCard()) {
            $bills = $provider->bills($fresh->external_id);
            $billsApplied = $syncBills->handle($fresh, $bills);
        }

        $usesFullHistory = $fresh->provider_history_synced_at === null;
        $transactions = $usesFullHistory
            ? $provider->transactions($fresh->external_id, $fresh->isCreditCard(), $this->firstSyncFloor($fresh), null)
            : $this->recentWindowTransactions($provider, $fresh, $connection);

        $result = $syncTransactions->handle($provider, $fresh, $transactions, $syncStartedAt, $categoriesById);
        $this->persistAccountProgress($fresh, count($result->insertedIds), $result->updatedCount, $billsApplied, $result->insertedIds);

        if ($usesFullHistory) {
            $accountMapper->markHistorySynced($fresh);
        }

        $accountMapper->settleOpeningBalance($fresh);

        return $fresh->isCreditCard() ? [$fresh, $bills] : null;
    }

    /**
     * Grava `added_count`/`updated_count`/`bills_count` e os itens desta
     * conta direto no banco, assim que ela termina — nunca acumulado em
     * memória para gravar tudo de uma vez no fim do job: se o job
     * cair no meio do caminho (conta 2 de 3, por exemplo), o progresso das
     * contas já sincronizadas nesta tentativa fica gravado mesmo assim, e um
     * retry (que reaproveita a mesma run — ver startOrResumeRun()) só
     * adiciona o que ainda faltava, em vez de recontar do zero.
     *
     * @param  list<int>  $insertedIds
     */
    private function persistAccountProgress(Account $account, int $added, int $updated, int $bills, array $insertedIds): void
    {
        if ($this->syncRun === null) {
            return;
        }

        BankSyncRun::query()->whereKey($this->syncRun->id)->incrementEach([
            'added_count' => $added,
            'updated_count' => $updated,
            'bills_count' => $bills,
        ]);

        $this->persistAddedItems($account, $insertedIds);
    }

    /**
     * Snapshot (`account_name`, `date`, `description`, `amount`, `direction`)
     * do que foi de fato inserido nesta conta, para o histórico de
     * sincronização (App\Domain\Banking\Models\BankSyncRunItem) continuar
     * legível mesmo que a transação seja apagada depois. O limite de
     * MAX_RUN_ITEMS é sempre lido do banco (contagem já gravada por contas
     * anteriores desta execução, ou por tentativas anteriores do mesmo
     * retry), nunca de um contador em memória, que reiniciaria do
     * zero a cada tentativa e deixaria passar mais do que o limite depois
     * de um retry.
     *
     * @param  list<int>  $insertedIds
     */
    private function persistAddedItems(Account $account, array $insertedIds): void
    {
        if ($this->syncRun === null || $insertedIds === []) {
            return;
        }

        $alreadyStored = BankSyncRunItem::query()->where('run_id', $this->syncRun->id)->count();
        $remaining = self::MAX_RUN_ITEMS - $alreadyStored;

        if ($remaining <= 0) {
            return;
        }

        $ids = array_slice($insertedIds, 0, $remaining);
        $transactions = Transaction::query()->whereIn('id', $ids)->get();

        if ($transactions->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();
        $userId = $this->syncRun->user_id;
        $runId = $this->syncRun->id;

        $rows = $transactions->map(fn (Transaction $transaction) => [
            'user_id' => $userId,
            'run_id' => $runId,
            'transaction_id' => $transaction->id,
            'account_name' => $account->name,
            'date' => $transaction->date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount->cents,
            'direction' => $transaction->direction->value,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        BankSyncRunItem::query()->insert($rows);
    }

    /**
     * Janela re-sincronizada que a reconciliação de pagamentos reconsidera
     * neste sync: os últimos 40 dias, ou desde o fechamento da fatura mais
     * antiga buscada agora (App\Domain\Banking\Actions\ReconcileCardPayments::earliestBillClosing(),
     * a mesma usada pelo comando `cards:reconcile-payments`), o que for mais
     * distante no passado — uma fatura ainda sem pagamento à vista pode ter
     * fechado há mais de 40 dias e ainda assim ser a mais antiga que
     * acabamos de buscar. Fora dessa janela, ReconcileCardPayments nunca
     * reconsidera nada neste sync (histórico mais antigo fica para o
     * comando).
     *
     * @param  list<ProviderBill>  $bills
     */
    private function reconciliationWindowFrom(array $bills): CarbonImmutable
    {
        $floor = CarbonImmutable::now()->subDays(self::RESYNC_WINDOW_DAYS);
        $earliestBillClosing = ReconcileCardPayments::earliestBillClosing($bills);

        return $earliestBillClosing !== null && $earliestBillClosing->lessThan($floor) ? $earliestBillClosing : $floor;
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
     * Syncs seguintes (conta já com o histórico completo buscado): além de
     * `createdAtFrom` (lançamentos novos desde o último sync, com 14 dias de
     * folga — como antes), busca também os últimos
     * RESYNC_WINDOW_DAYS dias por data (`dateFrom`), para pegar uma alteração
     * num lançamento já antigo que o banco relata diferente agora — status
     * pending→posted, valor, data, fatura, descrição (ver
     * App\Domain\Imports\Support\IngestionPlanner::decisionForExisting()) —,
     * não só lançamentos novos. BankProvider::transactions() não aceita os
     * dois filtros juntos, então são duas buscas, unidas por `external_id`
     * (a segunda só "ganha" de propósito: o mesmo lançamento não deveria
     * divergir entre as duas buscas do mesmo sync).
     *
     * @return list<ProviderTransaction>
     */
    private function recentWindowTransactions(BankProvider $provider, Account $account, BankConnection $connection): array
    {
        // last_synced_at da conexão é o normal; sem ele (não deveria
        // acontecer, já que provider_history_synced_at desta conta implica
        // que algum sync da conexão já terminou — mas por segurança), cai
        // para o próprio instante do histórico desta conta, melhor
        // estimativa do que "agora", que traria praticamente nada.
        $createdAtFrom = ($connection->last_synced_at ?? $account->provider_history_synced_at ?? CarbonImmutable::now())->subDays(14);
        $byCreatedAt = $provider->transactions($account->external_id, $account->isCreditCard(), null, $createdAtFrom);

        $dateFrom = CarbonImmutable::now()->subDays(self::RESYNC_WINDOW_DAYS);
        $byRecentDate = $provider->transactions($account->external_id, $account->isCreditCard(), $dateFrom, null);

        /** @var array<string, ProviderTransaction> $byId */
        $byId = [];

        foreach ($byCreatedAt as $transaction) {
            $byId[$transaction->id] = $transaction;
        }

        foreach ($byRecentDate as $transaction) {
            $byId[$transaction->id] = $transaction;
        }

        return array_values($byId);
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

            $this->syncRun = $this->startOrResumeRun($locked);

            $settings = $locked->settings ?? [];
            $settings['sync_meta'] = [
                'sync_started_at' => CarbonImmutable::now()->toIso8601String(),
                'start_status' => $locked->status->value,
                'sync_run_id' => $this->syncRun->id,
            ];
            $locked->update(['settings' => $settings]);

            return $locked;
        });
    }

    /**
     * Reaproveita a BankSyncRun `running` desta conexão cujo `job_uuid` bate
     * com $this->jobUuid() — um retry do próprio job (release() ou o
     * rethrow de ProviderUnavailable, que o Laravel tenta de novo depois do
     * backoff: o mesmo job, mesmo payload, mesmo jobUuid) — em vez de criar
     * outra linha para a mesma sincronização lógica. Nunca casa só por
     * estar `running`: qualquer outra `running` desta conexão (de um
     * job_uuid diferente, ou sem nenhum — travou antes de existir esta
     * coluna) é uma run presa (ex.: processo morto sem nunca chamar
     * failed()) e é fechada como erro por closeStuckRuns(), nunca
     * reaproveitada. Sem nenhuma `running` com este jobUuid, cria uma nova.
     */
    private function startOrResumeRun(BankConnection $connection): BankSyncRun
    {
        $jobUuid = $this->jobUuid();

        $existing = BankSyncRun::query()
            ->where('connection_id', $connection->id)
            ->where('status', SyncRunStatus::Running)
            ->where('job_uuid', $jobUuid)
            ->first();

        $this->closeStuckRuns($connection, $existing?->id);

        return $existing ?? BankSyncRun::create([
            'connection_id' => $connection->id,
            'trigger' => $this->trigger(),
            'job_uuid' => $jobUuid,
            'status' => SyncRunStatus::Running,
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Fecha como erro ("Sincronização interrompida") toda run `running`
     * desta conexão que não seja $exceptId — a run que este dispatch está
     * mesmo reaproveitando (ou nenhuma, quando esta é uma primeira
     * tentativa/novo dispatch). Protege contra uma run presa: um
     * processo morto no meio de um sync nunca chama finishRun()/failed(),
     * então sem isso ela ficaria `running` para sempre, bloqueando qualquer
     * leitura futura que espere "não tem sync rodando" — o próximo dispatch
     * desta conexão (agendado, manual, o que for) sempre limpa isso.
     */
    private function closeStuckRuns(BankConnection $connection, ?int $exceptId): void
    {
        BankSyncRun::query()
            ->where('connection_id', $connection->id)
            ->where('status', SyncRunStatus::Running)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update([
                'status' => SyncRunStatus::Error,
                'finished_at' => CarbonImmutable::now(),
                'error' => 'Sincronização interrompida',
            ]);
    }

    /**
     * Fecha $this->syncRun com o resultado final desta tentativa — chamado
     * de todo caminho terminal de sync() (sucesso, needs_reauth, erro
     * permanente, última tentativa de ProviderUnavailable) e de failed()
     * (via finalizeRunIfRunning(), numa instância nova que nunca passou por
     * aqui). Nunca chamado na saída transitória (release()/rethrow para
     * retry): a run continua `running` para a próxima tentativa reaproveitar.
     *
     * `added_count`/`updated_count`/`bills_count`/os itens não entram aqui:
     * já foram gravados direto no banco, conta por conta, por
     * persistAccountProgress() — regravá-los aqui com o que esta
     * instância acumulou em memória sobrescreveria (perderia) o que uma
     * tentativa anterior do mesmo retry já tinha persistido.
     *
     * Protegido contra corrida (lockForUpdate + checar status === Running):
     * se outra coisa já fechou esta run (não deveria acontecer, já que
     * ShouldBeUnique impede duas execuções desta conexão ao mesmo tempo),
     * não sobrescreve.
     */
    private function finishRun(SyncRunStatus $status, ?string $error): void
    {
        if ($this->syncRun === null) {
            return;
        }

        $runId = $this->syncRun->id;
        $attributes = [
            'status' => $status,
            'finished_at' => CarbonImmutable::now(),
            'refresh_requested' => $this->refreshRequested,
            'provider_updated_at' => $this->providerUpdatedAt,
            'warnings' => $this->warnings,
            'stats' => $this->reconcileStatsPayload(),
            'error' => $error,
        ];

        DB::transaction(function () use ($runId, $attributes) {
            $run = BankSyncRun::query()->whereKey($runId)->lockForUpdate()->first();

            if ($run === null || $run->status !== SyncRunStatus::Running) {
                return;
            }

            $run->update($attributes);
        });
    }

    /**
     * Resultado da reconciliação de pagamentos de fatura
     * (App\Domain\Banking\Actions\ReconcileCardPayments) desta execução, em
     * `stats` — pagamentos reconhecidos e transferências ligadas nunca geram
     * aviso (ver sync()); duplicatas ignoradas também entram em `warnings`.
     * Null quando não há nada de cartão nesta conexão (mais barato do que
     * gravar zeros sempre).
     *
     * @return array{payments_recognized: int, duplicates_ignored: int, transfers_linked: int}|null
     */
    private function reconcileStatsPayload(): ?array
    {
        if ($this->paymentsRecognized === 0 && $this->duplicatesIgnored === 0 && $this->transfersLinked === 0) {
            return null;
        }

        return [
            'payments_recognized' => $this->paymentsRecognized,
            'duplicates_ignored' => $this->duplicatesIgnored,
            'transfers_linked' => $this->transfersLinked,
        ];
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
     *
     * $adoptFingerprint (só informado pela chamada de sucesso, no fim de
     * sync()) é gravado só quando a conexão ainda está com
     * credential_fingerprint null: uma sincronização que terminou bem com
     * esta credencial é prova de que o item pertence a ela — adota a
     * credencial para uma conexão legada (criada sob a conta global antiga,
     * por variável de ambiente), sem nunca sobrescrever um fingerprint já
     * gravado (ver App\Domain\Banking\Models\BankConnection).
     */
    private function writeStatus(ConnectionStatus $status, ?string $error, ?CarbonImmutable $syncedAt = null, ?string $adoptFingerprint = null): void
    {
        $startStatus = $this->startStatus;

        DB::transaction(function () use ($startStatus, $status, $error, $syncedAt, $adoptFingerprint) {
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

            if ($adoptFingerprint !== null && $locked->credential_fingerprint === null) {
                $attributes['credential_fingerprint'] = $adoptFingerprint;
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
     * nova, sem $this->startStatus: pula a gravação do STATUS DA CONEXÃO
     * quando ela já está `needs_reauth`/`pending_link` (outra coisa já
     * decidiu o destino dela) ou quando ela está `active` e ficou assim
     * depois que esta tentativa começou (reconectada, ou já sincronizada de
     * novo com sucesso) — não regride um estado mais novo e melhor com um
     * erro de uma tentativa velha.
     *
     * A run, ao contrário, é SEMPRE finalizada aqui,
     * antes de qualquer um desses retornos antecipados: finalizeRunIfRunning()
     * já só toca a run se ela ainda estiver `running`, então nunca sobrescreve
     * nada — mas sem fechar antes dos retornos, uma run ficaria presa em
     * `running` para sempre exatamente nesses casos (conexão virou
     * needs_reauth/pending_link ou foi superada por um sync mais novo
     * enquanto esta tentativa, sem nunca ter chegado a chamar
     * finishRun(), ainda estava "em voo").
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

                if ($locked === null) {
                    return;
                }

                $message = 'Não foi possível falar com o banco. Tentaremos de novo.';
                $this->finalizeRunIfRunning($this->syncMeta($locked)['sync_run_id'], $message);

                if (in_array($locked->status, [ConnectionStatus::NeedsReauth, ConnectionStatus::PendingLink], true)) {
                    return;
                }

                if ($locked->status === ConnectionStatus::Active && $this->supersededByNewerSync($locked)) {
                    return;
                }

                $locked->update(['status' => ConnectionStatus::Error, 'last_error' => $message]);
            });
        });
    }

    /**
     * @return array{sync_started_at: ?CarbonImmutable, start_status: ?ConnectionStatus, sync_run_id: ?int}
     */
    private function syncMeta(BankConnection $connection): array
    {
        $meta = $connection->settings['sync_meta'] ?? null;

        if (! is_array($meta)) {
            return ['sync_started_at' => null, 'start_status' => null, 'sync_run_id' => null];
        }

        $startedAt = $meta['sync_started_at'] ?? null;
        $startStatus = $meta['start_status'] ?? null;
        $syncRunId = $meta['sync_run_id'] ?? null;

        return [
            'sync_started_at' => is_string($startedAt) ? CarbonImmutable::parse($startedAt) : null,
            'start_status' => is_string($startStatus) ? ConnectionStatus::tryFrom($startStatus) : null,
            'sync_run_id' => is_int($syncRunId) ? $syncRunId : null,
        ];
    }

    /**
     * Fecha, direto no banco (sem passar por $this->syncRun — failed() roda
     * numa instância recém-deserializada que nunca chamou lockedConnection()),
     * a run desta execução quando ela ainda estiver `running`. $runId vem de
     * settings.sync_meta (gravado por lockedConnection() na tentativa que
     * está terminando agora); null quando o job nem chegou a rodar handle()
     * desta vez (nada a fechar).
     */
    private function finalizeRunIfRunning(?int $runId, string $error): void
    {
        if ($runId === null) {
            return;
        }

        BankSyncRun::query()
            ->where('id', $runId)
            ->where('status', SyncRunStatus::Running)
            ->update(['status' => SyncRunStatus::Error, 'finished_at' => CarbonImmutable::now(), 'error' => $error]);
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
