<?php

use App\Domain\Accounts\Models\Account;
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
