<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;

it('saldo = saldo inicial + entradas - saídas lançadas e não ignoradas', function () {
    actingAsUser();
    $account = Account::factory()->create(['opening_balance' => 100000]);

    Transaction::factory()->for($account)->income()->create(['amount' => 50000]);
    Transaction::factory()->for($account)->create(['amount' => 20000]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'is_ignored' => true]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'status' => TransactionStatus::Projected]);
    Transaction::factory()->for($account)->create(['amount' => 99999, 'status' => TransactionStatus::Pending]);

    $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 130000);
    $this->getJson('/api/v1/accounts')->assertJsonPath('data.0.balance', 130000);
});

it('conta sem transações tem saldo igual ao inicial', function () {
    actingAsUser();
    $account = Account::factory()->create(['opening_balance' => -5000]);

    $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', -5000);
});

it('transferência move saldo entre as contas', function () {
    actingAsUser();
    $inter = Account::factory()->create(['name' => 'A', 'opening_balance' => 100000]);
    $nubank = Account::factory()->create(['name' => 'B', 'opening_balance' => 0]);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $inter->id, 'to_account_id' => $nubank->id,
        'date' => '2026-10-01', 'amount' => 30000, 'description' => 'Reserva',
    ])->assertCreated();

    $this->getJson('/api/v1/accounts')
        ->assertJsonPath('data.0.balance', 70000)
        ->assertJsonPath('data.1.balance', 30000);
});

it('devolve o saldo ao criar e editar conta', function () {
    actingAsUser();

    $id = $this->postJson('/api/v1/accounts', ['name' => 'Inter', 'type' => 'checking', 'opening_balance' => 1000])
        ->assertJsonPath('data.balance', 1000)
        ->json('data.id');

    $this->patchJson("/api/v1/accounts/{$id}", ['opening_balance' => 2000])->assertJsonPath('data.balance', 2000);
});

it('conta conectada usa o saldo informado pelo banco hoje, não saldo inicial mais lançamentos', function () {
    actingAsUser();
    $account = Account::factory()->create(['opening_balance' => 999999, 'provider_balance' => 150000]);
    Transaction::factory()->for($account)->create(['amount' => 500]); // já refletido no saldo do banco, não deve ser somado de novo

    $this->getJson("/api/v1/accounts/{$account->id}")->assertJsonPath('data.balance', 150000);
    $this->getJson('/api/v1/accounts')->assertJsonPath('data.0.balance', 150000);
});

it('cartão conectado ignora o saldo do banco e usa saldo inicial mais lançamentos', function () {
    actingAsUser();
    $card = Account::factory()->creditCard()->create(['opening_balance' => -2000, 'provider_balance' => -999999]);
    Transaction::factory()->for($card)->create(['amount' => 500]);

    $this->getJson("/api/v1/accounts/{$card->id}")->assertJsonPath('data.balance', -2500);
});
