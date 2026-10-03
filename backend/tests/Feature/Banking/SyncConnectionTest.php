<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ProviderAuthFailed;
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

function runConnectionSync(int $connectionId): void
{
    app()->call([new SyncConnection($connectionId), 'handle']);
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
    Sleep::fake();
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);

    $this->fake->items[$this->itemId] = providerItem([
        'id' => $this->itemId, 'status' => 'UPDATING', 'lastUpdatedAt' => CarbonImmutable::now()->subHours(21),
    ]);

    runConnectionSync($connection->id);

    $refreshCalls = array_filter($this->fake->calls, fn (array $c) => $c['method'] === 'refreshItem');
    expect($refreshCalls)->toHaveCount(1);
    Sleep::assertSleptTimes(30);
    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active);
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

it('syncs seguintes usam createdAtFrom = last_synced_at − 14 dias', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00'));
    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => '2026-09-20 10:00:00']);
    Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'acc-1']);
    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [providerAccount(['id' => 'acc-1'])];

    runConnectionSync($connection->id);

    $call = collect($this->fake->calls)->firstWhere('method', 'transactions');
    expect($call['args']['createdAtFrom'])->toBe('2026-09-06')
        ->and($call['args']['dateFrom'])->toBeNull();
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
