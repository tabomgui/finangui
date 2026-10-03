<?php

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function providerItem(array $overrides = []): ProviderItem
{
    return new ProviderItem(
        id: $overrides['id'] ?? '00000000-0000-0000-0000-0000000000a1',
        status: $overrides['status'] ?? 'UPDATED',
        clientUserId: $overrides['clientUserId'] ?? null,
        lastUpdatedAt: $overrides['lastUpdatedAt'] ?? null,
        institutionName: $overrides['institutionName'] ?? 'Banco Exemplo',
        institutionLogoUrl: $overrides['institutionLogoUrl'] ?? 'https://cdn.example.com/logo.png',
        errorMessage: $overrides['errorMessage'] ?? null,
    );
}

function providerAccount(array $overrides = []): ProviderAccount
{
    return new ProviderAccount(
        id: $overrides['id'] ?? '00000000-0000-0000-0000-0000000000b1',
        kind: $overrides['kind'] ?? 'checking',
        name: $overrides['name'] ?? 'Conta Corrente',
        number: $overrides['number'] ?? '1234',
        currency: $overrides['currency'] ?? 'BRL',
        balanceCents: $overrides['balanceCents'] ?? 100000,
        creditLimitCents: $overrides['creditLimitCents'] ?? null,
        closingDay: $overrides['closingDay'] ?? null,
        dueDay: $overrides['dueDay'] ?? null,
    );
}

beforeEach(function () {
    $this->fake = new FakeBankProvider;
    app()->instance(BankProvider::class, $this->fake);
    $this->user = actingAsUser();
});

describe('connect-token', function () {
    it('gera connect token com clientUserId = user:{id}', function () {
        $this->postJson('/api/v1/bank-connections/connect-token')
            ->assertOk()
            ->assertJsonPath('data.connect_token', 'fake-connect-token');

        expect($this->fake->calls[0])->toBe([
            'method' => 'connectToken',
            'args' => ['clientUserId' => 'user:'.$this->user->id, 'itemId' => null],
        ]);
    });

    it('com connection_id usa o item da conexão (modo atualização)', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id, 'external_id' => 'item-existing']);

        $this->postJson('/api/v1/bank-connections/connect-token', ['connection_id' => $connection->id])
            ->assertOk();

        expect($this->fake->calls[0]['args'])->toBe([
            'clientUserId' => 'user:'.$this->user->id,
            'itemId' => 'item-existing',
        ]);
    });

    it('connection_id de outro usuário → 404', function () {
        $other = User::factory()->create();
        $connection = BankConnection::factory()->create(['user_id' => $other->id]);

        $this->postJson('/api/v1/bank-connections/connect-token', ['connection_id' => $connection->id])
            ->assertNotFound();
    });
});

describe('criar conexão', function () {
    it('item com clientUserId diferente → 409 connection_item_mismatch', function () {
        $itemId = '00000000-0000-0000-0000-000000000c01';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:999999']);

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_item_mismatch');
    });

    it('item já conectado (mesmo external_id) por qualquer usuário → 409 connection_item_mismatch', function () {
        $itemId = '00000000-0000-0000-0000-000000000c02';
        $other = User::factory()->create();
        BankConnection::factory()->create(['user_id' => $other->id, 'external_id' => $itemId]);

        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id]);

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_item_mismatch');
    });

    it('item_id precisa ser um uuid', function () {
        $this->postJson('/api/v1/bank-connections', ['item_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_id');
    });

    it('sucesso: 201, conexão pending_link com nome/logo e provider_accounts com sugestão de vínculo', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a1';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id, 'institutionName' => 'Banco Exemplo']);
        $this->fake->accountsByItem[$itemId] = [
            providerAccount(['id' => 'acc-1', 'name' => 'Conta Corrente', 'number' => '5678']),
        ];

        $manual = Account::factory()->create(['name' => 'Banco Exemplo', 'type' => AccountType::Checking, 'currency' => 'BRL']);

        $response = $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertCreated();

        $response->assertJsonPath('data.connection.status', ConnectionStatus::PendingLink->value)
            ->assertJsonPath('data.connection.institution_name', 'Banco Exemplo')
            ->assertJsonPath('data.connection.institution_logo_url', 'https://cdn.example.com/logo.png')
            ->assertJsonPath('data.provider_accounts.0.external_id', 'acc-1')
            ->assertJsonPath('data.provider_accounts.0.suggested_account_id', $manual->id);

        expect(BankConnection::query()->where('external_id', $itemId)->first())
            ->status->toBe(ConnectionStatus::PendingLink);
    });

    it('sem conta manual parecida, a sugestão vem nula', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a2';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id]);
        $this->fake->accountsByItem[$itemId] = [providerAccount(['id' => 'acc-1'])];

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertCreated()
            ->assertJsonPath('data.provider_accounts.0.suggested_account_id', null);
    });
});

describe('vincular contas', function () {
    beforeEach(function () {
        $this->itemId = '00000000-0000-0000-0000-0000000000a1';
        $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId, 'clientUserId' => 'user:'.$this->user->id]);
        $this->fake->accountsByItem[$this->itemId] = [
            providerAccount(['id' => 'acc-1', 'name' => 'Conta Corrente', 'number' => '1111', 'kind' => 'checking', 'balanceCents' => 50000]),
            providerAccount(['id' => 'acc-2', 'name' => 'Cartão', 'number' => '2222', 'kind' => 'credit_card', 'balanceCents' => 30000, 'creditLimitCents' => 500000, 'closingDay' => 5, 'dueDay' => 15]),
        ];

        $created = $this->postJson('/api/v1/bank-connections', ['item_id' => $this->itemId])->assertCreated();
        $this->connectionId = $created->json('data.connection.id');
    });

    it('links precisa cobrir todas as contas do banco', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [['external_id' => 'acc-1', 'account_id' => null]],
        ])->assertUnprocessable()->assertJsonValidationErrors('links');
    });

    it('account_id de conta de outro usuário → 422 em links.1.account_id', function () {
        $other = User::factory()->create();
        $othersAccount = Account::factory()->create(['user_id' => $other->id]);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => $othersAccount->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.1.account_id');
    });

    it('account_id de conta de tipo diferente → 422 em links.1.account_id', function () {
        $wrongType = Account::factory()->create(['type' => AccountType::Checking, 'currency' => 'BRL']);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => $wrongType->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.1.account_id');
    });

    it('account_id de conta de moeda diferente → 422 em links.1.account_id', function () {
        $wrongCurrency = Account::factory()->creditCard()->create(['currency' => 'USD']);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => $wrongCurrency->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.1.account_id');
    });

    it('account_id de conta já conectada → 422 em links.1.account_id', function () {
        $otherConnection = BankConnection::factory()->create(['user_id' => $this->user->id]);
        $alreadyLinked = Account::factory()->creditCard()->create(['connection_id' => $otherConnection->id, 'external_id' => 'already-linked']);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => $alreadyLinked->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.1.account_id');
    });

    it('sem account_id cria conta nova e enfileira o sync', function () {
        Queue::fake();

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => null],
            ],
        ])->assertOk()->assertJsonPath('data.status', ConnectionStatus::Active->value);

        $checking = Account::query()->where('external_id', 'acc-1')->first();
        expect($checking->name)->toBe('Conta Corrente')
            ->and($checking->type)->toBe(AccountType::Checking)
            ->and($checking->currency)->toBe('BRL')
            ->and($checking->opening_balance->cents)->toBe(0)
            ->and($checking->icon)->toBe('landmark')
            ->and($checking->provider_balance->cents)->toBe(50000);

        $card = Account::query()->where('external_id', 'acc-2')->first();
        expect($card->type)->toBe(AccountType::CreditCard)
            ->and($card->icon)->toBe('credit-card')
            ->and($card->credit_limit->cents)->toBe(500000)
            ->and($card->closing_day)->toBe(5)
            ->and($card->due_day)->toBe(15)
            ->and($card->provider_balance->cents)->toBe(30000);

        expect(BankConnection::find($this->connectionId)->settings)->toBeNull();

        Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $this->connectionId && $job->userId === $this->user->id);
    });

    it('vincula a uma conta existente sem mudar nome, cor ou ícone', function () {
        Queue::fake();
        $manual = Account::factory()->create(['name' => 'Minha conta', 'color' => '#123456', 'icon' => 'piggy-bank']);
        $card = Account::factory()->creditCard()->create(['name' => 'Meu cartão', 'color' => '#654321', 'icon' => 'wallet']);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => $manual->id],
                ['external_id' => 'acc-2', 'account_id' => $card->id],
            ],
        ])->assertOk();

        $manual->refresh();
        expect($manual->name)->toBe('Minha conta')
            ->and($manual->color)->toBe('#123456')
            ->and($manual->icon)->toBe('piggy-bank')
            ->and($manual->connection_id)->toBe($this->connectionId)
            ->and($manual->external_id)->toBe('acc-1')
            ->and($manual->provider_balance->cents)->toBe(50000);

        $card->refresh();
        expect($card->name)->toBe('Meu cartão')
            ->and($card->connection_id)->toBe($this->connectionId)
            ->and($card->external_id)->toBe('acc-2');
    });

    it('vincular de novo → 409 connection_not_pending_link', function () {
        Queue::fake();

        $links = [
            ['external_id' => 'acc-1', 'account_id' => null],
            ['external_id' => 'acc-2', 'account_id' => null],
        ];

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", ['links' => $links])->assertOk();

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", ['links' => $links])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_not_pending_link');
    });

    it('conexão de outro usuário → 404', function () {
        $other = User::factory()->create();
        $othersConnection = BankConnection::factory()->create(['user_id' => $other->id]);

        $this->postJson("/api/v1/bank-connections/{$othersConnection->id}/link-accounts", ['links' => []])
            ->assertNotFound();
    });
});

describe('sincronizar manualmente', function () {
    it('202 e enfileira o job', function () {
        Queue::fake();
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $connection->id);
    });

    it('já na fila/rodando → 409 connection_sync_in_progress', function () {
        // Queue::fake() de propósito (não QUEUE_CONNECTION=sync, usado nos
        // outros testes): o lock único do job só fica retido enquanto ele
        // não termina — com a fila sync real, a primeira chamada roda e
        // libera o lock antes da segunda sequer começar, e o 409 nunca
        // aconteceria (mesmo problema resolvido em
        // ApplyRuleRetroactivelyTest::"a segunda responde 409...").
        Queue::fake();
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")->assertStatus(202);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_sync_in_progress');
    });

    it('conexão pending_link → 409', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_not_pending_link');
    });
});

describe('reconectado', function () {
    it('volta para active, limpa last_error e enfileira sync', function () {
        Queue::fake();
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected")
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Active->value)
            ->assertJsonPath('data.last_error', null);

        expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
            ->and($connection->last_error)->toBeNull();

        Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $connection->id);
    });
});

describe('desconectar', function () {
    it('chama deleteItem, exclui a conexão e as contas ficam manuais com o histórico intacto', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => 'item-x']);
        $account = Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-1']);
        $transaction = Transaction::factory()->create(['account_id' => $account->id, 'source' => TransactionSource::Pluggy]);

        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

        expect(BankConnection::find($connection->id))->toBeNull();

        $account->refresh();
        expect($account->connection_id)->toBeNull()
            ->and($account->external_id)->toBeNull();

        expect(Transaction::find($transaction->id))->not->toBeNull();

        expect($this->fake->calls)->toContain(['method' => 'deleteItem', 'args' => ['itemId' => 'item-x']]);
    });

    it('falha do provedor ao excluir o item não impede a desconexão local', function () {
        Log::spy();
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => 'item-x']);
        $this->fake->failNext(new RuntimeException('Pluggy fora do ar.'));

        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

        expect(BankConnection::find($connection->id))->toBeNull();
        Log::shouldHaveReceived('warning')->once();
    });

    it('conexão de outro usuário → 404', function () {
        $other = User::factory()->create();
        $connection = BankConnection::factory()->create(['user_id' => $other->id]);

        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNotFound();
    });
});

describe('banking_disabled', function () {
    beforeEach(function () {
        $this->fake->setEnabled(false);
    });

    it('bloqueia todas as rotas quando o provedor não está configurado', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);

        $this->getJson('/api/v1/bank-connections')->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson('/api/v1/bank-connections/connect-token')->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson('/api/v1/bank-connections', ['item_id' => '00000000-0000-0000-0000-0000000000a1'])->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/link-accounts", ['links' => []])->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected")->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    });
});

describe('listar', function () {
    it('lista as conexões com as contas vinculadas resumidas', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id]);
        Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-1', 'name' => 'Conta']);

        $this->getJson('/api/v1/bank-connections')
            ->assertOk()
            ->assertJsonPath('data.0.id', $connection->id)
            ->assertJsonPath('data.0.accounts.0.name', 'Conta')
            ->assertJsonPath('data.0.accounts.0.balance', 0);
    });

    it('é isolado por usuário', function () {
        $other = User::factory()->create();
        BankConnection::factory()->create(['user_id' => $other->id]);

        $this->getJson('/api/v1/bank-connections')->assertOk()->assertJsonCount(0, 'data');
    });
});
