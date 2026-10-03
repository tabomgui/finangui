<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncAccounts;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;

beforeEach(function () {
    actingAsUser();
    $this->action = app(SyncAccounts::class);
    $this->connection = BankConnection::factory()->active()->create();
});

function syncProviderAccount(array $overrides = []): ProviderAccount
{
    return new ProviderAccount(
        id: $overrides['id'] ?? 'acc-1',
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

it('atualiza o saldo informado de uma conta comum sem inverter o sinal', function () {
    $account = Account::factory()->create(['connection_id' => $this->connection->id, 'external_id' => 'acc-1']);

    $this->action->handle($this->connection, [syncProviderAccount(['id' => 'acc-1', 'balanceCents' => 55000])]);

    expect($account->refresh()->provider_balance->cents)->toBe(55000);
});

it('atualiza o saldo de um cartão com o sinal invertido (dívida negativa, como o saldo do app)', function () {
    $card = Account::factory()->creditCard()->create(['connection_id' => $this->connection->id, 'external_id' => 'acc-2']);

    $this->action->handle($this->connection, [
        syncProviderAccount(['id' => 'acc-2', 'kind' => 'credit_card', 'balanceCents' => 42000]),
    ]);

    expect($card->refresh()->provider_balance->cents)->toBe(-42000);
});

it('atualiza o limite do cartão a cada sync', function () {
    $card = Account::factory()->creditCard(limit: 100000)->create(['connection_id' => $this->connection->id, 'external_id' => 'acc-2']);

    $this->action->handle($this->connection, [
        syncProviderAccount(['id' => 'acc-2', 'kind' => 'credit_card', 'creditLimitCents' => 300000]),
    ]);

    expect($card->refresh()->credit_limit->cents)->toBe(300000);
});

it('nunca sobrescreve nome, cor, ícone ou dias do cartão editados pelo usuário', function () {
    $card = Account::factory()->creditCard(closingDay: 7, dueDay: 17)->create([
        'connection_id' => $this->connection->id, 'external_id' => 'acc-2',
        'name' => 'Meu cartão', 'color' => '#111111', 'icon' => 'wallet',
    ]);

    $this->action->handle($this->connection, [
        syncProviderAccount(['id' => 'acc-2', 'kind' => 'credit_card', 'closingDay' => 1, 'dueDay' => 10]),
    ]);

    $card->refresh();
    expect($card->name)->toBe('Meu cartão')
        ->and($card->color)->toBe('#111111')
        ->and($card->icon)->toBe('wallet')
        ->and($card->closing_day)->toBe(7)
        ->and($card->due_day)->toBe(17);
});

it('conta do banco que ainda não está vinculada vai para settings.unlinked_accounts, sem ser criada', function () {
    $this->action->handle($this->connection, [syncProviderAccount(['id' => 'acc-new', 'name' => 'Conta Nova'])]);

    expect(Account::query()->where('external_id', 'acc-new')->exists())->toBeFalse();

    $settings = $this->connection->refresh()->settings;
    expect($settings['unlinked_accounts'])->toHaveCount(1)
        ->and($settings['unlinked_accounts'][0]['id'])->toBe('acc-new')
        ->and($settings['unlinked_accounts'][0]['name'])->toBe('Conta Nova');
});

it('conta que volta a aparecer (já vinculada) sai de unlinked_accounts', function () {
    Account::factory()->create(['connection_id' => $this->connection->id, 'external_id' => 'acc-1']);
    $this->connection->update(['settings' => ['unlinked_accounts' => [['id' => 'acc-1', 'kind' => 'checking', 'name' => 'x', 'number' => null, 'currency' => 'BRL', 'balance_cents' => 0, 'credit_limit_cents' => null, 'closing_day' => null, 'due_day' => null]]]]);

    $this->action->handle($this->connection, [syncProviderAccount(['id' => 'acc-1'])]);

    expect($this->connection->refresh()->settings)->toBeNull();
});
