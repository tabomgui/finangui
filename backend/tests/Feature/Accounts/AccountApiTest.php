<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

it('cria conta', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'Inter',
        'type' => 'checking',
        'opening_balance' => 150000,
        'color' => '#f97316',
        'icon' => 'landmark',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Inter')
        ->assertJsonPath('data.type', 'checking')
        ->assertJsonPath('data.currency', 'BRL')
        ->assertJsonPath('data.opening_balance', 150000)
        ->assertJsonPath('data.is_archived', false);
});

it('cria conta com opening_balance enviado como string numérica', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'Inter',
        'type' => 'checking',
        'opening_balance' => '4590',
    ])->assertCreated()
        ->assertJsonPath('data.opening_balance', 4590);
});

it('atualiza opening_balance enviado como float sem parte fracionária', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->patchJson("/api/v1/accounts/{$account->id}", ['opening_balance' => 2000.0])
        ->assertOk()
        ->assertJsonPath('data.opening_balance', 2000);
});

it('recusa opening_balance acima do limite máximo', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'Inter', 'type' => 'checking', 'opening_balance' => 1_000_000_000_000_001,
    ])->assertStatus(422)->assertJsonValidationErrors('opening_balance');
});

it('valida a conta', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', ['name' => '', 'type' => 'bitcoin', 'opening_balance' => 10.5, 'currency' => 'real'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'opening_balance', 'currency']);
});

it('lista só contas do usuário, sem arquivadas por padrão', function () {
    $other = User::factory()->create();
    Account::factory()->create(['user_id' => $other->id]);

    actingAsUser();
    Account::factory()->create(['name' => 'Nubank']);
    Account::factory()->archived()->create(['name' => 'Antiga']);

    $this->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Nubank');

    $this->getJson('/api/v1/accounts?include_archived=1')->assertJsonCount(2, 'data');
});

it('atualiza e arquiva conta', function () {
    actingAsUser();
    $account = Account::factory()->create(['name' => 'Inter']);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['name' => 'Inter PJ', 'is_archived' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'Inter PJ')
        ->assertJsonPath('data.is_archived', true);
});

it('exclui conta cuja única "transação" é uma ocorrência de recorrência ainda não confirmada', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-01-05',
    ]);

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertNoContent();

    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(Recurrence::query()->whereKey($recurrence->id)->exists())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});

it('409 ao excluir conta com uma ocorrência de recorrência já adotada por importação/banco', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'pluggy', 'external_id' => 'ext-1',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-01-05',
    ]);

    $this->deleteJson("/api/v1/accounts/{$account->id}")
        ->assertStatus(409)->assertJsonPath('code', 'account_has_transactions');

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue();
});

it('arquivar a conta pausa suas recorrências e exclui as ocorrências não confirmadas', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create(['account_id' => $account->id, 'user_id' => $user->id, 'is_active' => true]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-01-05',
    ]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['is_archived' => true])
        ->assertOk()->assertJsonPath('data.is_archived', true);

    expect($recurrence->refresh()->is_active)->toBeFalse()
        ->and(Transaction::query()->where('recurrence_id', $recurrence->id)->exists())->toBeFalse();
});

it('não permite trocar a moeda depois de criada', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->patchJson("/api/v1/accounts/{$account->id}", ['currency' => 'USD'])->assertOk();

    expect($account->fresh()->currency)->toBe('BRL');
});

it('exclui conta sem transações', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertNoContent();

    expect(Account::count())->toBe(0);
});

it('retorna 404 para conta de outro usuário', function () {
    $other = User::factory()->create();
    $foreign = Account::factory()->create(['user_id' => $other->id]);

    actingAsUser();

    $this->getJson("/api/v1/accounts/{$foreign->id}")->assertNotFound();
    $this->patchJson("/api/v1/accounts/{$foreign->id}", ['name' => 'x'])->assertNotFound();
});

it('expõe connection_id, provider_balance e provider_synced_at de uma conta vinculada', function () {
    actingAsUser();
    $connection = BankConnection::factory()->active()->create();
    $account = Account::factory()->create([
        'connection_id' => $connection->id,
        'external_id' => 'acc-ext-1',
        'provider_balance' => 150000,
        'provider_synced_at' => '2026-03-07T10:00:00-03:00',
    ]);

    $this->getJson("/api/v1/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.connection_id', $connection->id)
        ->assertJsonPath('data.provider_balance', 150000)
        ->assertJsonPath('data.provider_synced_at', '2026-03-07T10:00:00-03:00');
});

it('uma conta sem conexão expõe connection_id e provider_balance como null', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->getJson("/api/v1/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.connection_id', null)
        ->assertJsonPath('data.provider_balance', null)
        ->assertJsonPath('data.provider_synced_at', null);
});

it('UpdateAccountRequest ignora campos de vínculo bancário enviados pelo cliente', function () {
    actingAsUser();
    $connection = BankConnection::factory()->active()->create();
    $account = Account::factory()->create(['connection_id' => $connection->id, 'external_id' => 'acc-ext-2']);

    $this->patchJson("/api/v1/accounts/{$account->id}", [
        'connection_id' => null,
        'external_id' => 'outro',
        'provider_balance' => 999,
    ])->assertOk();

    $account->refresh();
    expect($account->connection_id)->toBe($connection->id)
        ->and($account->external_id)->toBe('acc-ext-2')
        ->and($account->provider_balance)->toBeNull();
});
