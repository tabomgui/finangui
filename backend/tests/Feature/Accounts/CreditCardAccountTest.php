<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
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
        ->assertJsonPath('data.due_day', null)->assertJsonPath('data.last_four', null);
});

it('deixar de ser cartão exclui as faturas vazias', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    CardStatement::factory()->create(['account_id' => $card->id]);
    CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-04-03', 'due_date' => '2026-04-10']);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'checking'])->assertOk();

    expect(CardStatement::where('account_id', $card->id)->count())->toBe(0);
});

it('não troca o tipo de/para cartão quando a conta já tem lançamentos', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['account_id' => $card->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'checking'])
        ->assertStatus(409)->assertJsonPath('code', 'account_type_locked');
});

it('não transforma conta comum em cartão quando já tem lançamentos (mantém os dados)', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
    Transaction::factory()->create(['account_id' => $account->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", [
        'type' => 'credit_card', 'credit_limit' => 500000, 'closing_day' => 3, 'due_day' => 10,
    ])->assertStatus(409)->assertJsonPath('code', 'account_type_locked');

    $account->refresh();
    expect($account->type->value)->toBe('checking');
    expect($account->credit_limit)->toBeNull();
});

it('troca entre tipos que não são cartão mesmo com lançamentos', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['account_id' => $account->id]);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['type' => 'savings'])->assertOk();
});

it('reenviar type=credit_card num cartão sem campos de cartão mantém os valores', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'credit_card'])
        ->assertOk()
        ->assertJsonPath('data.credit_limit', 500000)
        ->assertJsonPath('data.closing_day', 3)
        ->assertJsonPath('data.due_day', 10)
        ->assertJsonPath('data.last_four', '4242');
});

it('cartão continuando cartão recusa credit_limit nulo', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['credit_limit' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['credit_limit']);
});

it('cartão continuando cartão aceita last_four nulo para limpar', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['last_four' => null])
        ->assertOk()->assertJsonPath('data.last_four', null);
});

it('conta comum recusa credit_limit em qualquer formato sem trocar de tipo', function () {
    $user = actingAsUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);

    foreach ([100, [], null] as $value) {
        $this->patchJson("/api/v1/accounts/{$account->id}", ['credit_limit' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors(['credit_limit']);
    }
});

it('recusa deixar de ser cartão enviando credit_limit junto', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $this->patchJson("/api/v1/accounts/{$card->id}", ['type' => 'checking', 'credit_limit' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors(['credit_limit']);
});

it('cria conta comum enviando campos de cartão explicitamente nulos', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'Inter', 'type' => 'checking',
        'credit_limit' => null, 'closing_day' => null, 'due_day' => null, 'last_four' => null,
    ])->assertCreated();
});

it('recusa credit_limit negativo', function () {
    actingAsUser();

    $this->postJson('/api/v1/accounts', [
        'name' => 'X', 'type' => 'credit_card', 'credit_limit' => -1, 'closing_day' => 1, 'due_day' => 10,
    ])->assertUnprocessable()->assertJsonValidationErrors(['credit_limit']);
});
