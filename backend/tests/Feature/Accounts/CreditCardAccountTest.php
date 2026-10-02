<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;

it('cria um cartão com limite, dias e final', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'Nubank', 'type' => 'credit_card',
        'credit_limit' => 500000, 'closing_day' => 3, 'due_day' => 10, 'last_four' => '1234',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'credit_card')
        ->assertJsonPath('data.credit_limit', 500000)
        ->assertJsonPath('data.closing_day', 3)
        ->assertJsonPath('data.due_day', 10)
        ->assertJsonPath('data.last_four', '1234');
});

it('exige limite e dias para cartão', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', ['name' => 'Nubank', 'type' => 'credit_card'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credit_limit', 'closing_day', 'due_day']);
});

it('valida faixa dos dias e formato do final', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'X', 'type' => 'credit_card', 'credit_limit' => 1, 'closing_day' => 0, 'due_day' => 32, 'last_four' => '12a4',
    ])->assertUnprocessable()->assertJsonValidationErrors(['closing_day', 'due_day', 'last_four']);
});

it('recusa campos de cartão em conta que não é cartão', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', ['name' => 'Inter', 'type' => 'checking', 'credit_limit' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors(['credit_limit']);
});

it('conta comum devolve campos de cartão nulos', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', ['name' => 'Inter', 'type' => 'checking'])
        ->assertCreated()->assertJsonPath('data.credit_limit', null)->assertJsonPath('data.closing_day', null);
});

it('edita limite e dias de um cartão', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['credit_limit' => 900000, 'due_day' => 15])
        ->assertOk()->assertJsonPath('data.credit_limit', 900000)->assertJsonPath('data.due_day', 15);
});

it('transforma conta sem lançamentos em cartão exigindo os campos', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['type' => 'credit_card'])
        ->assertUnprocessable()->assertJsonValidationErrors(['credit_limit', 'closing_day', 'due_day']);

    $this->patchJson("/api/v1/accounts/{$account->id}", [
        'type' => 'credit_card', 'credit_limit' => 1000, 'closing_day' => 1, 'due_day' => 8,
    ])->assertOk()->assertJsonPath('data.type', 'credit_card');
});

it('deixar de ser cartão limpa os campos de cartão', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'checking'])
        ->assertOk()->assertJsonPath('data.credit_limit', null)->assertJsonPath('data.closing_day', null)
        ->assertJsonPath('data.last_four', null);
});

it('não troca o tipo de/para cartão quando a conta já tem lançamentos', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['account_id' => $card->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'checking'])
        ->assertStatus(409)->assertJsonPath('code', 'account_type_locked');
});

it('troca entre tipos que não são cartão mesmo com lançamentos', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['account_id' => $account->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['type' => 'savings'])->assertOk();
});
