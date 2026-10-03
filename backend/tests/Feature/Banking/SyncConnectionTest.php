<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;
use Throwable;

function runConnectionSync(int $connectionId): SyncConnection
{
    $job = new SyncConnection($connectionId);
    app()->call([$job, 'handle']);

    return $job;
}

/**
 * BankProvider que delega tudo a $delegate, menos $failingMethod, que
 * sempre lança $exception — para testar como o job reage a uma falha numa
 * chamada específica (ex.: refreshItem) sem afetar as outras.
 */
function providerFailingOn(FakeBankProvider $delegate, string $failingMethod, Throwable $exception): BankProvider
{
    return new class($delegate, $failingMethod, $exception) implements BankProvider
    {
        public function __construct(
            private FakeBankProvider $delegate,
            private string $failingMethod,
            private Throwable $exception,
        ) {}

        public function enabled(): bool
        {
            return $this->delegate->enabled();
        }

        public function connectToken(string $clientUserId, ?string $itemId = null): string
        {
            return $this->delegate->connectToken($clientUserId, $itemId);
        }

        public function item(string $itemId): ProviderItem
        {
            return $this->failingMethod === 'item' ? throw $this->exception : $this->delegate->item($itemId);
        }

        public function refreshItem(string $itemId): void
        {
            if ($this->failingMethod === 'refreshItem') {
                throw $this->exception;
            }

            $this->delegate->refreshItem($itemId);
        }

        public function deleteItem(string $itemId): void
        {
            $this->delegate->deleteItem($itemId);
        }

        public function accounts(string $itemId): array
        {
            return $this->failingMethod === 'accounts' ? throw $this->exception : $this->delegate->accounts($itemId);
        }

        public function transactions(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
        {
            if ($this->failingMethod === 'transactions') {
                throw $this->exception;
            }

            return $this->delegate->transactions($accountId, $creditCard, $dateFrom, $createdAtFrom);
        }

        public function bills(string $accountId): array
        {
            return $this->failingMethod === 'bills' ? throw $this->exception : $this->delegate->bills($accountId);
        }

        public function categories(): array
        {
            return $this->delegate->categories();
        }
    };
}

beforeEach(function () {
    $this->fake = new FakeBankProvider;
    app()->instance(BankProvider::class, $this->fake);
    $this->user = actingAsUser();
    $this->itemId = '00000000-0000-0000-0000-0000000000f1';
});

it('sincroniza contas, faturas e transações, e marca a conexão como sincronizada', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-2']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'acc-1', 'balanceCents' => 50000]),
        providerAccount(['id' => 'acc-2', 'kind' => 'credit_card', 'balanceCents' => 20000, 'creditLimitCents' => 500000]),
    ];
    $this->fake->billsByAccount['acc-2'] = [providerBill(['id' => 'bill-1', 'dueDate' => '2026-11-10', 'closingDate' => '2026-10-31', 'totalCents' => 20000])];
    $this->fake->transactionsByAccount['acc-1'] = [syncProviderTransaction(['id' => 'tx-1'])];

    runConnectionSync($connection->id);

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_error)->toBeNull()
        ->and($connection->last_synced_at)->not->toBeNull();

    expect($checking->refresh()->provider_balance->cents)->toBe(50000)
        ->and($card->refresh()->provider_balance->cents)->toBe(-20000)
        ->and($card->credit_limit->cents)->toBe(500000);

    expect(CardStatement::query()->where('account_id', $card->id)->where('external_id', 'bill-1')->exists())->toBeTrue();
    expect(Transaction::query()->where('account_id', $checking->id)->where('external_id', 'tx-1')->first()->source)
        ->toBe(TransactionSource::Pluggy);
});

it('item com mais de 20h dispara refresh e espera a atualização a cada 3s, até o máximo de 90s', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);

    $this->fake->items[$this->itemId] = providerItem([
        'id' => $this->itemId, 'status' => 'UPDATED', 'lastUpdatedAt' => CarbonImmutable::now()->subHours(21),
    ]);

    runConnectionSync($connection->id);

    $refreshCalls = array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'refreshItem');
    expect($refreshCalls)->toHaveCount(1);
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('item já em UPDATING só espera (a cada 3s, até 90s) sem pedir outro refresh', function () {
    Sleep::fake();
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'UPDATING']);

    runConnectionSync($connection->id);

    expect(array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'refreshItem'))->toBeEmpty();
    Sleep::assertSleptTimes(30);
    // Esgotado o tempo máximo de espera: segue com o que o banco já tem.
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('item sem lastUpdatedAt (nunca atualizado) nunca pede refresh', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'UPDATED', 'lastUpdatedAt' => null]);

    runConnectionSync($connection->id);

    expect(array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'refreshItem'))->toBeEmpty();
});

it('item recém-atualizado não dispara refresh', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'lastUpdatedAt' => CarbonImmutable::now()->subHours(1)]);

    runConnectionSync($connection->id);

    expect(array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'refreshItem'))->toBeEmpty();
});

it('item em LOGIN_ERROR vira needs_reauth, sem buscar contas ou transações', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'LOGIN_ERROR', 'errorMessage' => 'Senha incorreta.']);

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsReauth)
        ->and($connection->last_error)->toBe('Senha incorreta.')
        ->and(array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'accounts'))->toBeEmpty();
});

it('item em WAITING_USER_INPUT também vira needs_reauth', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'WAITING_USER_INPUT', 'errorMessage' => null]);

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsReauth)
        ->and($connection->last_error)->toBe('O banco pediu para reconectar.');
});

it('item OUTDATED segue com o que o banco já tem', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'OUTDATED']);

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('ProviderUnavailable propaga (não é engolida) para o retry da fila cuidar', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->failNext(new ProviderUnavailable('Serviço fora do ar.'));

    expect(fn () => runConnectionSync($connection->id))->toThrow(ProviderUnavailable::class);
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('ProviderAuthFailed marca a conexão como erro sem relançar (sem retry)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->failNext(new ProviderAuthFailed);

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('Credenciais do servidor recusadas pela Pluggy.');
});

it('failed() grava um erro genérico depois de esgotadas as tentativas', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);

    (new SyncConnection($connection->id))->failed(new ProviderUnavailable('timeout'));

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('Não foi possível falar com o banco. Tentaremos de novo.');
});

it('conexão excluída antes do job rodar: não faz nada', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
    $id = $connection->id;
    $connection->delete();

    // Simula o contexto real de um worker de fila: nenhum usuário
    // autenticado antes do job rodar (actingAsUser(), no beforeEach, só
    // existe para as factories acima — não representa a fila de verdade).
    Auth::guard()->forgetUser();

    runConnectionSync($id);

    expect(Auth::hasUser())->toBeFalse();
});

it('primeiro sync usa dateFrom de 365 dias atrás', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00'));
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => null]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];

    runConnectionSync($connection->id);

    $call = collect($this->fake->calls)->firstWhere('method', 'transactions');
    expect($call['args']['dateFrom'])->toBe('2025-10-03')
        ->and($call['args']['createdAtFrom'])->toBeNull();
});

it('syncs seguintes usam createdAtFrom = last_synced_at − 14 dias, para uma conta que já teve o histórico sincronizado', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00'));
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => '2026-09-20 10:00:00']);
    // provider_history_synced_at já preenchido: é isso (por conta, não a
    // conexão) que decide createdAtFrom em vez de dateFrom — ver
    // App\Domain\Banking\Jobs\SyncConnection.
    Account::factory()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1',
        'provider_history_synced_at' => '2026-09-20 10:00:00',
    ]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];

    runConnectionSync($connection->id);

    $call = collect($this->fake->calls)->firstWhere('method', 'transactions');
    expect($call['args']['createdAtFrom'])->toBe('2026-09-06')
        ->and($call['args']['dateFrom'])->toBeNull();
});

it('conta vinculada depois, numa conexão já sincronizada antes, ainda usa dateFrom de 365 dias no primeiro sync dela', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00'));
    // A conexão já tem last_synced_at (não é o primeiro sync DELA), mas a
    // conta é nova (provider_history_synced_at nulo) — o piso é por conta.
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => '2026-09-20 10:00:00']);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];

    runConnectionSync($connection->id);

    $call = collect($this->fake->calls)->firstWhere('method', 'transactions');
    expect($call['args']['dateFrom'])->toBe('2025-10-03')
        ->and($call['args']['createdAtFrom'])->toBeNull();

    expect(Account::query()->where('external_id', 'acc-1')->first()->provider_history_synced_at)->not->toBeNull();
});

it('Auth::hasUser() volta a false depois do job, mesmo com sucesso', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    Auth::guard()->forgetUser();

    runConnectionSync($connection->id);

    expect(Auth::hasUser())->toBeFalse();
});

it('não sobrescreve uma reconexão concorrente (active) com um needs_reauth calculado antes dela', function () {
    // A conexão começa `error` (é assim que o job chega a rodar: o gate
    // inicial só deixa active/error passar). Simula a reconexão do usuário
    // acontecendo bem no meio da execução deste job — entre ele ler o item
    // (ainda LOGIN_ERROR, resposta de antes da reconexão) e tentar gravar o
    // resultado: outro processo (App\Domain\Banking\Actions\MarkReconnected)
    // já deixou a conexão `active` quando o item() é consultado.
    $connection = BankConnection::factory()->create([
        'user_id' => $this->user->id, 'external_id' => $this->itemId,
        'status' => ConnectionStatus::Error, 'last_error' => 'erro antigo', 'last_synced_at' => now(),
    ]);

    $delegate = new FakeBankProvider;
    $delegate->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'LOGIN_ERROR', 'errorMessage' => 'Senha incorreta.']);

    $provider = new class($delegate, $connection->id) implements BankProvider
    {
        public function __construct(private FakeBankProvider $delegate, private int $reconnectDuring) {}

        public function enabled(): bool
        {
            return $this->delegate->enabled();
        }

        public function connectToken(string $clientUserId, ?string $itemId = null): string
        {
            return $this->delegate->connectToken($clientUserId, $itemId);
        }

        public function item(string $itemId): ProviderItem
        {
            $result = $this->delegate->item($itemId);

            // A reconexão (MarkReconnected) acontece, de propósito, só agora
            // — depois do job já ter lido o item (LOGIN_ERROR, resposta de
            // antes dela) mas antes dele tentar gravar o resultado.
            BankConnection::query()->withoutGlobalScopes()->whereKey($this->reconnectDuring)->update([
                'status' => ConnectionStatus::Active->value, 'last_error' => null,
            ]);

            return $result;
        }

        public function refreshItem(string $itemId): void
        {
            $this->delegate->refreshItem($itemId);
        }

        public function deleteItem(string $itemId): void
        {
            $this->delegate->deleteItem($itemId);
        }

        public function accounts(string $itemId): array
        {
            return $this->delegate->accounts($itemId);
        }

        public function transactions(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
        {
            return $this->delegate->transactions($accountId, $creditCard, $dateFrom, $createdAtFrom);
        }

        public function bills(string $accountId): array
        {
            return $this->delegate->bills($accountId);
        }

        public function categories(): array
        {
            return $this->delegate->categories();
        }
    };
    app()->instance(BankProvider::class, $provider);

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_error)->toBeNull();
});

it('ProviderRequestFailed 404 (item sumiu no banco) grava uma mensagem específica, sem relançar', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
    app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderRequestFailed(404)));

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('A conexão não existe mais no banco. Conecte de novo.');
});

it('ProviderRequestFailed fora de 404 grava uma mensagem genérica de recusa, sem relançar', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
    app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderRequestFailed(422)));

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('O banco recusou a sincronização.');
});

it('refreshItem indisponível (ex.: 429) não derruba o sync: loga e segue com o item já buscado', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $delegate = new FakeBankProvider;
    $delegate->items[$this->itemId] = providerItem(['id' => $this->itemId, 'status' => 'UPDATED', 'lastUpdatedAt' => CarbonImmutable::now()->subHours(21)]);

    app()->instance(BankProvider::class, providerFailingOn($delegate, 'refreshItem', new ProviderUnavailable('429', retryAfter: 30)));

    runConnectionSync($connection->id);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_error)->toBeNull();
});

it('ProviderUnavailable com retryAfter solta o job de volta na fila com essa espera, em vez do backoff padrão', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
    app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderUnavailable('429', retryAfter: 45)));

    $job = (new SyncConnection($connection->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(45);
    // status inalterado: release() não grava nada, só devolve à fila.
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('conta vinculada que não apareceu na lista de contas do banco neste sync é pulada (log), sem chamar bills/transactions para ela', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-gone']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = []; // o banco não devolveu "acc-gone" neste sync

    runConnectionSync($connection->id);

    expect(array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'transactions'))->toBeEmpty()
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Active);
});

it('ajusta o saldo de abertura de uma conta nova (criada pelo vínculo) depois do primeiro sync completo via job', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => null]);
    $account = Account::factory()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1',
        'opening_balance' => 0, 'provider_balance' => 100000,
    ]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1', 'balanceCents' => 100000])];
    $this->fake->transactionsByAccount['acc-1'] = [syncProviderTransaction(['id' => 'tx-1', 'amountCents' => 30000])];

    runConnectionSync($connection->id);

    $account->refresh();
    // saldo do banco (100000) = opening + (−30000) ⇒ opening = 130000.
    expect($account->opening_balance->cents)->toBe(130000)
        ->and($account->provider_opening_set_at)->not->toBeNull();
});

it('nunca ajusta o saldo de abertura de um cartão (fica sempre zero)', function () {
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => null]);
    $card = Account::factory()->creditCard()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-2', 'opening_balance' => 0,
    ]);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-2', 'kind' => 'credit_card', 'balanceCents' => 50000])];
    $this->fake->transactionsByAccount['acc-2'] = [syncProviderTransaction(['id' => 'tx-1', 'amountCents' => 20000])];

    runConnectionSync($connection->id);

    $card->refresh();
    expect($card->opening_balance->cents)->toBe(0)
        ->and($card->provider_opening_set_at)->toBeNull();
});

describe('última tentativa e proteção de failed() contra regressão', function () {
    it('ProviderUnavailable na última tentativa grava erro direto, sem liberar de novo nem relançar', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
        app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderUnavailable('fora do ar')));

        $job = (new SyncConnection($connection->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
            ->and($connection->last_error)->toBe('Não foi possível falar com o banco. Tentaremos de novo.');
    });

    it('clampa retryAfter em 900s no máximo antes de liberar de volta na fila', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);
        app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderUnavailable('429', retryAfter: 3600)));

        $job = (new SyncConnection($connection->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(900);
    });

    it('failed() não sobrescreve uma conexão que já virou needs_reauth', function () {
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId]);

        (new SyncConnection($connection->id))->failed(new ProviderUnavailable('timeout'));

        expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsReauth);
    });

    it('failed() não sobrescreve uma conexão que já virou pending_link', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'status' => 'pending_link']);

        (new SyncConnection($connection->id))->failed(new ProviderUnavailable('timeout'));

        expect($connection->refresh()->status)->toBe(ConnectionStatus::PendingLink);
    });

    it('failed() não sobrescreve uma reconexão concorrente (active) ocorrida depois que esta tentativa começou', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'status' => 'error', 'last_error' => 'erro antigo']);
        app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderUnavailable('fora do ar')));

        // handle(): lockedConnection() grava settings.sync_meta (start_status
        // = error) antes de falar com o provedor; a falha propaga (não é a
        // última tentativa, sem retryAfter) — ver sync().
        try {
            app()->call([new SyncConnection($connection->id), 'handle']);
        } catch (ProviderUnavailable) {
            // esperado.
        }

        // Reconecta (outra execução) enquanto este retry ainda estava de pé.
        BankConnection::query()->withoutGlobalScopes()->whereKey($connection->id)->update(['status' => 'active', 'last_error' => null]);

        (new SyncConnection($connection->id))->failed(new ProviderUnavailable('timeout'));

        expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
            ->and($connection->last_error)->toBeNull();
    });

    it('failed() grava o erro quando a conexão continua active e sem sync mais novo depois do início desta tentativa', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()->subHours(1)]);
        app()->instance(BankProvider::class, providerFailingOn(new FakeBankProvider, 'item', new ProviderUnavailable('fora do ar')));

        try {
            app()->call([new SyncConnection($connection->id), 'handle']);
        } catch (ProviderUnavailable) {
            // esperado.
        }

        (new SyncConnection($connection->id))->failed(new ProviderUnavailable('timeout'));

        expect($connection->refresh()->status)->toBe(ConnectionStatus::Error);
    });
});
