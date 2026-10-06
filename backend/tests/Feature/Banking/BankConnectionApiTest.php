<?php

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\LinkAccounts;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\AccountNoLongerLinkable;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Support\PendingProviderAccounts;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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
    $this->fake = fakeBankProvider();
    $this->user = actingAsUser();
    verifiedBankCredential($this->user);
});

describe('connect-token', function () {
    it('gera connect token com clientUserId = user:{id}, sem item_id no corpo', function () {
        $this->postJson('/api/v1/bank-connections/connect-token')
            ->assertOk()
            ->assertJsonPath('data.connect_token', 'fake-connect-token')
            ->assertJsonMissingPath('data.item_id');

        expect($this->fake->calls[0])->toBe([
            'method' => 'connectToken',
            'args' => ['clientUserId' => 'user:'.$this->user->id, 'itemId' => null],
        ]);
    });

    it('com connection_id usa o item da conexão (modo atualização) e devolve item_id', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id, 'external_id' => 'item-existing']);

        $this->postJson('/api/v1/bank-connections/connect-token', ['connection_id' => $connection->id])
            ->assertOk()
            ->assertJsonPath('data.item_id', 'item-existing');

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

    it('ProviderUnavailable (banco fora do ar) vira 503 provider_unavailable', function () {
        $this->fake->failNext(new ProviderUnavailable('fora do ar'));

        $this->postJson('/api/v1/bank-connections/connect-token')
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('message', 'O banco não respondeu. Tente de novo em instantes.');
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

    it('item sem contas → 409 connection_without_accounts', function () {
        $itemId = '00000000-0000-0000-0000-000000000c03';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id]);
        $this->fake->accountsByItem[$itemId] = [];

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_without_accounts');

        expect(BankConnection::query()->where('external_id', $itemId)->exists())->toBeFalse();
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
            ->assertJsonPath('data.provider_accounts.0.kind', AccountType::Checking->value)
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

    it('cada conta manual só é sugerida a uma conta do banco (sugestão exclusiva)', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a3';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id, 'institutionName' => 'Banco Exemplo']);
        $this->fake->accountsByItem[$itemId] = [
            providerAccount(['id' => 'acc-1', 'name' => 'Conta 1']),
            providerAccount(['id' => 'acc-2', 'name' => 'Conta 2']),
        ];

        $manual = Account::factory()->create(['name' => 'Banco Exemplo', 'type' => AccountType::Checking, 'currency' => 'BRL']);

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertCreated()
            ->assertJsonPath('data.provider_accounts.0.suggested_account_id', $manual->id)
            ->assertJsonPath('data.provider_accounts.1.suggested_account_id', null);
    });

    it('conta manual arquivada não entra na sugestão', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a4';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id, 'institutionName' => 'Banco Exemplo']);
        $this->fake->accountsByItem[$itemId] = [providerAccount(['id' => 'acc-1'])];

        Account::factory()->archived()->create(['name' => 'Banco Exemplo', 'type' => AccountType::Checking, 'currency' => 'BRL']);

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])
            ->assertCreated()
            ->assertJsonPath('data.provider_accounts.0.suggested_account_id', null);
    });

    it('settings.pending_accounts guarda só os últimos 4 dígitos do número da conta', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a5';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id]);
        $this->fake->accountsByItem[$itemId] = [providerAccount(['id' => 'acc-1', 'number' => '00112233445566'])];

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])->assertCreated();

        $connection = BankConnection::query()->where('external_id', $itemId)->first();
        expect($connection->settings['pending_accounts'][0]['number'])->toBe('5566');
    });

    it('o unique de (provider, external_id) lança UniqueConstraintViolationException — o tipo que o catch de CreateConnection espera', function () {
        // CreateConnection intercepta especificamente este tipo (não só
        // QueryException) para converter a corrida rara entre o exists()
        // e o create() em 409 connection_item_mismatch. Reproduzir a
        // corrida de verdade exigiria duas requisições concorrentes de
        // fato; este teste confirma a premissa (o tipo de exceção que o
        // driver do Postgres lança para esse unique), não o caminho
        // ponta a ponta.
        BankConnection::factory()->create(['external_id' => 'dup-item']);

        expect(fn () => BankConnection::factory()->create(['external_id' => 'dup-item']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('grava credential_fingerprint a partir da credencial atual do usuário', function () {
        $itemId = '00000000-0000-0000-0000-000000000c04';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id]);
        $this->fake->accountsByItem[$itemId] = [providerAccount(['id' => 'acc-1'])];

        $credential = BankCredential::query()->where('user_id', $this->user->id)->first();

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])->assertCreated();

        $connection = BankConnection::query()->where('external_id', $itemId)->first();
        expect($connection->credential_fingerprint)->toBe(BankCredential::fingerprint($credential->client_id));
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

    it('links com external_id repetido → 422', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-1', 'account_id' => null],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.0.external_id');
    });

    it('links com mais linhas do que contas pendentes → 422', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => null],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links');
    });

    it('account_id repetido em duas linhas → 422', function () {
        $manual = Account::factory()->create();

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => $manual->id],
                ['external_id' => 'acc-2', 'account_id' => $manual->id],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('links');
    });

    it('duas linhas com account_id nulo não são tratadas como repetidas', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => null],
            ],
        ])->assertOk();
    });

    it('account_id pode ser omitido (não só null) para criar conta nova', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1'],
                ['external_id' => 'acc-2'],
            ],
        ])->assertOk();

        expect(Account::query()->where('external_id', 'acc-1')->exists())->toBeTrue();
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

    it('account_id de conta arquivada → 422 em links.1.account_id', function () {
        $archived = Account::factory()->creditCard()->archived()->create();

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => $archived->id],
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
            ->and($card->last_four)->toBe('2222')
            // Cartão: sinal invertido (dívida negativa, como o saldo do app) —
            // App\Domain\Banking\Support\AccountMapper::appBalanceCents().
            ->and($card->provider_balance->cents)->toBe(-30000);

        expect(BankConnection::find($this->connectionId)->settings)->toBeNull();

        Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $this->connectionId);
    });

    it('vincula a uma conta existente sem mudar nome, cor ou ícone', function () {
        Queue::fake();
        $manual = Account::factory()->create(['name' => 'Minha conta', 'color' => '#123456', 'icon' => 'piggy-bank']);
        $card = Account::factory()->creditCard()->create(['name' => 'Meu cartão', 'color' => '#654321', 'icon' => 'wallet', 'last_four' => null]);

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
            ->and($card->external_id)->toBe('acc-2')
            ->and($card->last_four)->toBe('2222');
    });

    it('o vínculo remove só settings.pending_accounts, preservando outras chaves de settings', function () {
        Queue::fake();
        $connection = BankConnection::find($this->connectionId);
        $connection->update(['settings' => array_merge($connection->settings, ['foo' => 'bar'])]);

        $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
            'links' => [
                ['external_id' => 'acc-1', 'account_id' => null],
                ['external_id' => 'acc-2', 'account_id' => null],
            ],
        ])->assertOk();

        expect(BankConnection::find($this->connectionId)->settings)->toBe(['foo' => 'bar']);
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

    it('vincula mesmo com um sync já a caminho (ConnectionSyncInProgress é engolido)', function () {
        $lock = Cache::lock(UniqueLock::getKey(new SyncConnection($this->connectionId)));
        $lock->get();

        try {
            $this->postJson("/api/v1/bank-connections/{$this->connectionId}/link-accounts", [
                'links' => [
                    ['external_id' => 'acc-1', 'account_id' => null],
                    ['external_id' => 'acc-2', 'account_id' => null],
                ],
            ])->assertOk();
        } finally {
            $lock->release();
        }
    });

    it('a trava da conta detecta, sob lock, quando ela deixou de estar disponível (corrida)', function () {
        $manual = Account::factory()->create();
        $elsewhere = BankConnection::factory()->create(['user_id' => $this->user->id]);
        $manual->update(['connection_id' => $elsewhere->id, 'external_id' => 'grabbed-elsewhere']);

        $connection = BankConnection::find($this->connectionId);
        $links = [
            ['external_id' => 'acc-1', 'account_id' => $manual->id],
            ['external_id' => 'acc-2', 'account_id' => null],
        ];

        expect(fn () => app(LinkAccounts::class)->handle($connection, $links))
            ->toThrow(AccountNoLongerLinkable::class);
    });

    it('a trava da conta detecta, sob lock, quando ela foi arquivada (corrida)', function () {
        $manual = Account::factory()->archived()->create();

        $connection = BankConnection::find($this->connectionId);
        $links = [
            ['external_id' => 'acc-1', 'account_id' => $manual->id],
            ['external_id' => 'acc-2', 'account_id' => null],
        ];

        expect(fn () => app(LinkAccounts::class)->handle($connection, $links))
            ->toThrow(AccountNoLongerLinkable::class);
    });

    it('conexão de outro usuário → 404', function () {
        $other = User::factory()->create();
        $othersConnection = BankConnection::factory()->create(['user_id' => $other->id]);

        $this->postJson("/api/v1/bank-connections/{$othersConnection->id}/link-accounts", ['links' => []])
            ->assertNotFound();
    });
});

describe('contas novas do banco numa conexão já active (unlinked_accounts)', function () {
    beforeEach(function () {
        $this->connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id]);
    });

    it('unlinked_accounts vem vazio por padrão', function () {
        $this->getJson('/api/v1/bank-connections')
            ->assertOk()
            ->assertJsonPath('data.0.unlinked_accounts', []);
    });

    it('expõe as contas novas do banco (settings.unlinked_accounts) com sugestão de vínculo', function () {
        $manual = Account::factory()->create(['name' => $this->connection->institution_name, 'type' => AccountType::Checking, 'currency' => 'BRL']);
        $this->connection->update(['settings' => [
            'unlinked_accounts' => PendingProviderAccounts::toSettings([providerAccount(['id' => 'acc-new', 'number' => '9999'])]),
        ]]);

        $this->getJson('/api/v1/bank-connections')
            ->assertOk()
            ->assertJsonPath('data.0.unlinked_accounts.0.external_id', 'acc-new')
            ->assertJsonPath('data.0.unlinked_accounts.0.suggested_account_id', $manual->id);
    });

    it('vincula só algumas das contas novas por vez, mantendo as outras em unlinked_accounts', function () {
        $this->connection->update(['settings' => [
            'unlinked_accounts' => PendingProviderAccounts::toSettings([
                providerAccount(['id' => 'acc-new-1']),
                providerAccount(['id' => 'acc-new-2']),
            ]),
        ]]);

        $this->postJson("/api/v1/bank-connections/{$this->connection->id}/link-accounts", [
            'links' => [['external_id' => 'acc-new-1']],
        ])->assertOk()->assertJsonPath('data.status', ConnectionStatus::Active->value);

        expect(Account::query()->where('external_id', 'acc-new-1')->exists())->toBeTrue()
            ->and(Account::query()->where('external_id', 'acc-new-2')->exists())->toBeFalse();

        $settings = $this->connection->refresh()->settings;
        expect($settings['unlinked_accounts'])->toHaveCount(1)
            ->and($settings['unlinked_accounts'][0]['id'])->toBe('acc-new-2');
    });

    it('vincular a última conta nova limpa settings.unlinked_accounts', function () {
        $this->connection->update(['settings' => [
            'unlinked_accounts' => PendingProviderAccounts::toSettings([providerAccount(['id' => 'acc-new-1'])]),
        ]]);

        $this->postJson("/api/v1/bank-connections/{$this->connection->id}/link-accounts", [
            'links' => [['external_id' => 'acc-new-1']],
        ])->assertOk();

        // "unlinked_accounts" some de settings; o sync que o vínculo
        // enfileira na hora ainda grava settings.sync_meta — não checa o
        // settings inteiro, só a chave desta feature.
        expect($this->connection->refresh()->settings['unlinked_accounts'] ?? null)->toBeNull();
    });

    it('external_id fora de unlinked_accounts → 422', function () {
        $this->connection->update(['settings' => [
            'unlinked_accounts' => PendingProviderAccounts::toSettings([providerAccount(['id' => 'acc-new-1'])]),
        ]]);

        $this->postJson("/api/v1/bank-connections/{$this->connection->id}/link-accounts", [
            'links' => [['external_id' => 'nao-existe']],
        ])->assertUnprocessable()->assertJsonValidationErrors('links.0.external_id');
    });

    it('sem nenhuma conta nova do banco para vincular → 409 connection_not_pending_link', function () {
        $this->postJson("/api/v1/bank-connections/{$this->connection->id}/link-accounts", [
            'links' => [['external_id' => 'acc-x']],
        ])->assertStatus(409)->assertJsonPath('code', 'connection_not_pending_link');
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

    it('conexão pending_link → 409 connection_not_linked', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_not_linked');
    });

    it('conexão needs_reauth → 409 connection_needs_reauth', function () {
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_needs_reauth');
    });
});

describe('reconectado', function () {
    it('volta para active, limpa last_error e enfileira sync', function () {
        Queue::fake();
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected", ['item_id' => $connection->external_id])
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Active->value)
            ->assertJsonPath('data.last_error', null);

        expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
            ->and($connection->last_error)->toBeNull();

        Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $connection->id);
    });

    it('sem item_id → 422', function () {
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected")
            ->assertStatus(422)
            ->assertJsonValidationErrors('item_id');
    });

    it('item_id diferente do item da conexão → 409 connection_item_mismatch', function () {
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id, 'external_id' => 'item-x']);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected", ['item_id' => 'item-y'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_item_mismatch');

        expect($connection->refresh()->status)->not->toBe(ConnectionStatus::Active);
    });

    it('reconectado numa conexão pending_link → 409 connection_not_linked', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected", ['item_id' => $connection->external_id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'connection_not_linked');
    });

    it('reconecta mesmo com um sync já a caminho (ConnectionSyncInProgress é engolido)', function () {
        $connection = BankConnection::factory()->needsReauth()->create(['user_id' => $this->user->id]);
        $lock = Cache::lock(UniqueLock::getKey(new SyncConnection($connection->id)));
        $lock->get();

        try {
            $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected", ['item_id' => $connection->external_id])
                ->assertOk();
        } finally {
            $lock->release();
        }
    });
});

describe('desconectar', function () {
    it('chama deleteItem, exclui a conexão e as contas ficam manuais com o histórico intacto', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => 'item-x']);
        $account = Account::factory()->create([
            'connection_id' => $connection->id,
            'external_id' => 'acc-1',
            'provider_balance' => 12345,
            'provider_synced_at' => now(),
            'provider_sync_from' => '2026-01-01',
            'provider_opening_set_at' => now(),
            'provider_history_synced_at' => now(),
        ]);
        $transaction = Transaction::factory()->create(['account_id' => $account->id, 'source' => TransactionSource::Pluggy]);

        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

        expect(BankConnection::find($connection->id))->toBeNull();

        $account->refresh();
        expect($account->connection_id)->toBeNull()
            ->and($account->external_id)->toBeNull()
            ->and($account->provider_balance)->toBeNull()
            ->and($account->provider_synced_at)->toBeNull()
            ->and($account->provider_sync_from)->toBeNull()
            ->and($account->provider_opening_set_at)->toBeNull()
            ->and($account->provider_history_synced_at)->toBeNull();

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
        // EnsureBankingEnabled (connect-token/store/link-accounts/reconnected/sync)
        // lê direto do banco (BankCredential::isVerifiedFor()), não da fábrica fake —
        // some com a credencial criada no beforeEach de fora para simular isso.
        BankCredential::query()->delete();
        // destroy fica fora daquele middleware; quem checa credenciais ali é
        // DisconnectConnection, direto na fábrica — esta ainda precisa saber
        // que está "desligada".
        disableBankProvider();
    });

    it('bloqueia as rotas que falam com o provedor', function () {
        $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);

        $this->postJson('/api/v1/bank-connections/connect-token')->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson('/api/v1/bank-connections', ['item_id' => '00000000-0000-0000-0000-0000000000a1'])->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/link-accounts", ['links' => []])->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected")->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
        $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    });

    it('index e destroy continuam funcionando (destroy pula a chamada ao provedor)', function () {
        $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => 'item-x']);

        $this->getJson('/api/v1/bank-connections')->assertOk();
        $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

        expect($this->fake->calls)->not->toContain(['method' => 'deleteItem', 'args' => ['itemId' => 'item-x']]);
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
            ->assertJsonPath('data.0.accounts.0.balance', 0)
            ->assertJsonPath('data.0.accounts.0.currency', 'BRL')
            ->assertJsonPath('data.0.accounts.0.color', '#f97316')
            ->assertJsonPath('data.0.accounts.0.icon', 'landmark')
            ->assertJsonPath('data.0.accounts.0.is_archived', false)
            ->assertJsonPath('data.0.pending_accounts', []);
    });

    it('é isolado por usuário', function () {
        $other = User::factory()->create();
        BankConnection::factory()->create(['user_id' => $other->id]);

        $this->getJson('/api/v1/bank-connections')->assertOk()->assertJsonCount(0, 'data');
    });

    it('conexão pending_link aparece com pending_accounts para retomar o vínculo', function () {
        $itemId = '00000000-0000-0000-0000-0000000000a6';
        $this->fake->items[$itemId] = providerItem(['id' => $itemId, 'clientUserId' => 'user:'.$this->user->id, 'institutionName' => 'Banco Exemplo']);
        $this->fake->accountsByItem[$itemId] = [providerAccount(['id' => 'acc-1', 'number' => '5678'])];

        $manual = Account::factory()->create(['name' => 'Banco Exemplo', 'type' => AccountType::Checking, 'currency' => 'BRL']);

        $this->postJson('/api/v1/bank-connections', ['item_id' => $itemId])->assertCreated();

        $this->getJson('/api/v1/bank-connections')
            ->assertOk()
            ->assertJsonPath('data.0.status', ConnectionStatus::PendingLink->value)
            ->assertJsonPath('data.0.pending_accounts.0.external_id', 'acc-1')
            ->assertJsonPath('data.0.pending_accounts.0.suggested_account_id', $manual->id);
    });
});
