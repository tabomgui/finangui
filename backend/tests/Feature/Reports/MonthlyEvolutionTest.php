<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    actingAsUser();
    $this->account = Account::factory()->create();
});

it('traz meses vazios com zero', function () {
    $response = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-03')->assertOk();

    expect($response->json('data.months'))->toBe([
        ['month' => '2026-01', 'income' => 0, 'expense' => 0, 'net' => 0],
        ['month' => '2026-02', 'income' => 0, 'expense' => 0, 'net' => 0],
        ['month' => '2026-03', 'income' => 0, 'expense' => 0, 'net' => 0],
    ]);
});

it('soma receita, despesa e líquido por mês na base de compra', function () {
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-10', 'amount' => 30000]);
    Transaction::factory()->for($this->account)->income()->create(['date' => '2026-01-20', 'amount' => 50000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-02-05', 'amount' => 10000]);

    $response = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-02&basis=purchase')->assertOk();

    expect($response->json('data.months'))->toBe([
        ['month' => '2026-01', 'income' => 50000, 'expense' => 30000, 'net' => 20000],
        ['month' => '2026-02', 'income' => 0, 'expense' => 10000, 'net' => -10000],
    ]);
});

it('move a compra do cartão para o mês do vencimento da fatura em basis=statement', function () {
    $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();

    // Fechamento dia 3: compra em 15/jan entra no ciclo que fecha em 3/fev e
    // vence em 10/fev — cruza o mês.
    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-01-15', 'amount' => 40000, 'direction' => 'out', 'description' => 'Compra',
    ])->assertCreated();

    $purchase = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-02&basis=purchase')->assertOk();
    expect($purchase->json('data.months.0.expense'))->toBe(40000)
        ->and($purchase->json('data.months.1.expense'))->toBe(0);

    $statement = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-02&basis=statement')->assertOk();
    expect($statement->json('data.months.0.expense'))->toBe(0)
        ->and($statement->json('data.months.1.expense'))->toBe(40000);
});

it('não muda contas que não são de cartão em basis=statement', function () {
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-15', 'amount' => 40000]);

    $purchase = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-02&basis=purchase')->assertOk();
    $statement = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-02&basis=statement')->assertOk();

    expect($statement->json('data.months'))->toBe($purchase->json('data.months'));
});

it('compra de cartão sem fatura vinculada cai na própria data em basis=statement', function () {
    $card = Account::factory()->creditCard()->create();
    Transaction::factory()->for($card)->create(['date' => '2026-01-15', 'amount' => 15000, 'statement_id' => null]);

    $statement = $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-01&basis=statement')->assertOk();

    expect($statement->json('data.months.0.expense'))->toBe(15000);
});

it('restringe o período a 24 meses e exige from <= to', function () {
    $this->getJson('/api/v1/reports/monthly?from=2026-03&to=2026-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/reports/monthly?from=2024-01&to=2026-02')
        ->assertStatus(422)->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/reports/monthly?from=2024-01&to=2025-12')->assertOk();
});

it('isola a evolução mensal por usuário', function () {
    $user = auth()->user();
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-10', 'amount' => 10000]);

    actingAsUser();
    $otherAccount = Account::factory()->create();
    Transaction::factory()->for($otherAccount)->create(['date' => '2026-01-10', 'amount' => 999999]);

    $this->actingAs($user);

    $this->getJson('/api/v1/reports/monthly?from=2026-01&to=2026-01')
        ->assertJsonPath('data.months.0.expense', 10000);
});
