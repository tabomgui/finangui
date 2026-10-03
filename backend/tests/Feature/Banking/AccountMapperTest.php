<?php

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    actingAsUser();
    $this->mapper = new AccountMapper;
    $this->connection = BankConnection::factory()->create();
});

/**
 * array_key_exists (não ??): number precisa aceitar null explícito nos
 * testes que confirmam o comportamento sem número do banco — "??" trataria
 * a chave presente com valor null do mesmo jeito que ausente, e sempre
 * cairia no default '1234'.
 */
function mapperAccount(array $overrides = []): ProviderAccount
{
    return new ProviderAccount(
        id: $overrides['id'] ?? 'acc-1',
        kind: $overrides['kind'] ?? 'checking',
        name: $overrides['name'] ?? 'Conta Corrente',
        number: array_key_exists('number', $overrides) ? $overrides['number'] : '1234',
        currency: $overrides['currency'] ?? 'BRL',
        balanceCents: $overrides['balanceCents'] ?? 10000,
        creditLimitCents: $overrides['creditLimitCents'] ?? null,
        closingDay: $overrides['closingDay'] ?? null,
        dueDay: $overrides['dueDay'] ?? null,
    );
}

it('cria uma conta corrente nova vinculada, com abertura zero', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount());

    expect($account->type)->toBe(AccountType::Checking)
        ->and($account->opening_balance->cents)->toBe(0)
        ->and($account->icon)->toBe('landmark')
        ->and($account->connection_id)->toBe($this->connection->id)
        ->and($account->external_id)->toBe('acc-1')
        ->and($account->provider_balance->cents)->toBe(10000)
        ->and($account->provider_synced_at)->not->toBeNull();
});

it('cria um cartão novo com limite e dias (fallback 1/10 sem os dias do banco)', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount([
        'kind' => 'credit_card',
        'creditLimitCents' => 500000,
    ]));

    expect($account->type)->toBe(AccountType::CreditCard)
        ->and($account->icon)->toBe('credit-card')
        ->and($account->credit_limit->cents)->toBe(500000)
        ->and($account->closing_day)->toBe(1)
        ->and($account->due_day)->toBe(10)
        ->and($account->last_four)->toBe('1234');
});

it('cartão novo sem número do banco fica sem last_four', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount([
        'kind' => 'credit_card',
        'number' => null,
    ]));

    expect($account->last_four)->toBeNull();
});

it('conta corrente nova nunca recebe last_four', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount(['kind' => 'checking']));

    expect($account->last_four)->toBeNull();
});

it('cria um cartão usando os dias do banco quando informados', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount([
        'kind' => 'credit_card',
        'closingDay' => 7,
        'dueDay' => 17,
    ]));

    expect($account->closing_day)->toBe(7)->and($account->due_day)->toBe(17);
});

it('vincula uma conta existente sem alterar nome, cor ou ícone', function () {
    $existing = Account::factory()->create(['name' => 'Minha conta', 'color' => '#abcdef', 'icon' => 'piggy-bank']);

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['id' => 'acc-9', 'balanceCents' => 77700]));

    expect($linked->name)->toBe('Minha conta')
        ->and($linked->color)->toBe('#abcdef')
        ->and($linked->icon)->toBe('piggy-bank')
        ->and($linked->connection_id)->toBe($this->connection->id)
        ->and($linked->external_id)->toBe('acc-9')
        ->and($linked->provider_balance->cents)->toBe(77700);
});

it('preenche last_four ao vincular um cartão existente que ainda não tinha', function () {
    $existing = Account::factory()->creditCard()->create(['last_four' => null]);

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['kind' => 'credit_card', 'number' => '98765']));

    expect($linked->last_four)->toBe('8765');
});

it('não sobrescreve last_four já preenchido ao vincular', function () {
    $existing = Account::factory()->creditCard()->create(['last_four' => '9999']);

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['kind' => 'credit_card', 'number' => '1234']));

    expect($linked->last_four)->toBe('9999');
});

it('conta corrente vinculada nunca recebe last_four', function () {
    $existing = Account::factory()->create(['last_four' => null]);

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['kind' => 'checking']));

    expect($linked->last_four)->toBeNull();
});

it('atualiza saldo e limite de uma conta já vinculada, sem tocar em nome/dias', function () {
    $account = Account::factory()->creditCard(closingDay: 5, dueDay: 15, limit: 100000)
        ->create(['name' => 'Cartão', 'connection_id' => $this->connection->id, 'external_id' => 'acc-5']);

    $updated = $this->mapper->updateLinked($account, mapperAccount([
        'id' => 'acc-5',
        'kind' => 'credit_card',
        'balanceCents' => 42000,
        'creditLimitCents' => 200000,
    ]));

    expect($updated->provider_balance->cents)->toBe(42000)
        ->and($updated->credit_limit->cents)->toBe(200000)
        ->and($updated->name)->toBe('Cartão')
        ->and($updated->closing_day)->toBe(5)
        ->and($updated->due_day)->toBe(15);
});

it('vincular uma conta existente sem lançamento guarda provider_sync_from na data de criação da conta', function () {
    $existing = Account::factory()->create();

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['id' => 'acc-9']));

    expect($linked->provider_sync_from->toDateString())->toBe($existing->created_at->toDateString());
});

it('vincular uma conta existente com lançamentos guarda provider_sync_from na data do mais antigo', function () {
    $existing = Account::factory()->create();
    Transaction::factory()->create(['account_id' => $existing->id, 'date' => '2026-01-20']);
    Transaction::factory()->create(['account_id' => $existing->id, 'date' => '2026-02-10']);

    $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['id' => 'acc-9']));

    expect($linked->provider_sync_from->toDateString())->toBe('2026-01-20');
});

it('conta criada pelo vínculo nunca recebe provider_sync_from', function () {
    $account = $this->mapper->createLinked($this->connection, mapperAccount());

    expect($account->provider_sync_from)->toBeNull();
});

describe('settleOpeningBalance', function () {
    it('ajusta a abertura de uma conta criada pelo vínculo para bater com o saldo do banco', function () {
        $account = $this->mapper->createLinked($this->connection, mapperAccount(['balanceCents' => 100000]));
        $account->update(['provider_balance' => 100000]);
        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 30000, 'direction' => 'out', 'status' => 'posted']);
        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 5000, 'direction' => 'in', 'status' => 'posted']);

        $this->mapper->settleOpeningBalance($account);
        $account->refresh();

        // saldo do banco (100000) = opening + (−30000 + 5000) ⇒ opening = 125000
        expect($account->opening_balance->cents)->toBe(125000)
            ->and($account->provider_opening_set_at)->not->toBeNull();
    });

    it('não conta pendentes nem ignoradas no ajuste', function () {
        $account = $this->mapper->createLinked($this->connection, mapperAccount(['balanceCents' => 100000]));
        $account->update(['provider_balance' => 100000]);
        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 30000, 'direction' => 'out', 'status' => 'pending']);
        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 9000, 'direction' => 'out', 'status' => 'posted', 'is_ignored' => true]);

        $this->mapper->settleOpeningBalance($account);
        $account->refresh();

        expect($account->opening_balance->cents)->toBe(100000);
    });

    it('nunca roda numa conta manual vinculada (provider_sync_from preenchido)', function () {
        $existing = Account::factory()->create();
        $linked = $this->mapper->linkExisting($existing, $this->connection, mapperAccount(['balanceCents' => 100000]));
        $linked->update(['opening_balance' => 777, 'provider_balance' => 100000]);

        $this->mapper->settleOpeningBalance($linked);
        $linked->refresh();

        expect($linked->opening_balance->cents)->toBe(777)
            ->and($linked->provider_opening_set_at)->toBeNull();
    });

    it('é idempotente: rodar de novo não muda o que já foi ajustado', function () {
        $account = $this->mapper->createLinked($this->connection, mapperAccount(['balanceCents' => 100000]));
        $account->update(['provider_balance' => 100000]);
        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 30000, 'direction' => 'out', 'status' => 'posted']);

        $this->mapper->settleOpeningBalance($account);
        $account->refresh();
        $firstOpening = $account->opening_balance->cents;

        Transaction::factory()->create(['account_id' => $account->id, 'amount' => 1000, 'direction' => 'out', 'status' => 'posted']);
        $this->mapper->settleOpeningBalance($account);
        $account->refresh();

        expect($account->opening_balance->cents)->toBe($firstOpening);
    });
});
