<?php

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;

beforeEach(function () {
    actingAsUser();
    $this->mapper = new AccountMapper;
    $this->connection = BankConnection::factory()->create();
});

function mapperAccount(array $overrides = []): ProviderAccount
{
    return new ProviderAccount(
        id: $overrides['id'] ?? 'acc-1',
        kind: $overrides['kind'] ?? 'checking',
        name: $overrides['name'] ?? 'Conta Corrente',
        number: $overrides['number'] ?? '1234',
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
        ->and($account->due_day)->toBe(10);
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
