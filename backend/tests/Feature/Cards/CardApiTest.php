<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 6)->startOfDay());
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20, limit: 500000)->create(['user_id' => $this->user->id, 'name' => 'Nubank']);
});

it('lista só cartões, com limite usado, projetado e disponível', function () {
    Account::factory()->create(['user_id' => $this->user->id]);
    $this->postJson('/api/v1/transactions', ['account_id' => $this->card->id, 'date' => '2026-03-05', 'amount' => 120000, 'direction' => 'out', 'description' => 'TV', 'installments' => 2]);
    Transaction::factory()->create(['account_id' => $this->card->id, 'amount' => 5000, 'is_ignored' => true]);

    $this->getJson('/api/v1/cards')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Nubank')
        ->assertJsonPath('data.0.limit.used', 60000)
        ->assertJsonPath('data.0.limit.projected', 60000)
        ->assertJsonPath('data.0.limit.available', 380000)
        ->assertJsonPath('data.0.balance', -60000);
});

it('saldo inicial negativo conta como limite usado', function () {
    $this->card->update(['opening_balance' => -10000]);

    $this->getJson("/api/v1/cards/{$this->card->id}")->assertOk()->assertJsonPath('data.limit.used', 10000);
});

it('pagamento libera limite', function () {
    $checking = Account::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->create(['account_id' => $this->card->id, 'amount' => 30000]);
    $this->postJson('/api/v1/transfers', ['from_account_id' => $checking->id, 'to_account_id' => $this->card->id, 'date' => '2026-03-06', 'amount' => 10000, 'description' => 'Pagamento']);

    $this->getJson("/api/v1/cards/{$this->card->id}")->assertJsonPath('data.limit.used', 20000);
});

it('transação pendente conta como limite usado', function () {
    Transaction::factory()->create(['account_id' => $this->card->id, 'amount' => 15000, 'status' => 'pending']);

    $this->getJson("/api/v1/cards/{$this->card->id}")->assertJsonPath('data.limit.used', 15000);
});

it('projetada que não é de parcelamento não conta como limite projetado', function () {
    Transaction::factory()->create(['account_id' => $this->card->id, 'amount' => 15000, 'status' => 'projected', 'installment_plan_id' => null]);

    $this->getJson("/api/v1/cards/{$this->card->id}")->assertJsonPath('data.limit.projected', 0);
});

it('fatura atual é a próxima a vencer, com dias até o vencimento', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);
    $mar = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $this->getJson("/api/v1/cards/{$this->card->id}")->assertOk()
        ->assertJsonPath('data.current_statement.id', $mar->id)
        ->assertJsonPath('data.current_statement.status', 'open')
        ->assertJsonPath('data.current_statement.days_until_due', 14);
});

it('cartão sem faturas tem fatura atual nula', function () {
    $this->getJson("/api/v1/cards/{$this->card->id}")->assertOk()->assertJsonPath('data.current_statement', null);
});

it('arquivados só com include_archived', function () {
    $this->card->update(['is_archived' => true]);

    $this->getJson('/api/v1/cards')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/cards?include_archived=1')->assertJsonCount(1, 'data');
});

it('include_archived aceita "true"/"false" (como o openapi-fetch do frontend envia)', function () {
    $this->card->update(['is_archived' => true]);

    $this->getJson('/api/v1/cards?include_archived=false')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/cards?include_archived=true')->assertOk()->assertJsonCount(1, 'data');
});

it('conta que não é cartão dá 404 no detalhe e na prévia', function () {
    $checking = Account::factory()->create(['user_id' => $this->user->id]);

    $this->getJson("/api/v1/cards/{$checking->id}")->assertNotFound();
    $this->getJson("/api/v1/cards/{$checking->id}/statement-preview?date=2026-03-10")->assertNotFound();
});

it('cartão de outro usuário dá 404, inclusive na prévia', function () {
    $other = Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id]);

    $this->getJson("/api/v1/cards/{$other->id}")->assertNotFound();
    $this->getJson("/api/v1/cards/{$other->id}/statements")->assertNotFound();
    $this->getJson("/api/v1/cards/{$other->id}/statement-preview?date=2026-03-10")->assertNotFound();
});

it('id de cartão não numérico dá 404, não erro', function () {
    $this->getJson('/api/v1/cards/abc')->assertNotFound();
});

it('lista as faturas do cartão em ordem de vencimento, com totais', function () {
    $this->postJson('/api/v1/transactions', ['account_id' => $this->card->id, 'date' => '2026-03-05', 'amount' => 1000, 'direction' => 'out', 'description' => 'A']);
    $this->postJson('/api/v1/transactions', ['account_id' => $this->card->id, 'date' => '2026-02-05', 'amount' => 2000, 'direction' => 'out', 'description' => 'B']);

    $this->getJson("/api/v1/cards/{$this->card->id}/statements")->assertOk()
        ->assertJsonPath('data.0.due_date', '2026-02-20')
        ->assertJsonPath('data.0.total', 2000)
        ->assertJsonPath('data.0.status', 'closed')
        ->assertJsonPath('data.1.due_date', '2026-03-20')
        ->assertJsonPath('data.1.total', 1000);
});

it('prévia da fatura de uma data sem criar nada', function () {
    $this->getJson("/api/v1/cards/{$this->card->id}/statement-preview?date=2026-03-10")->assertOk()
        ->assertJsonPath('data.statement_id', null)
        ->assertJsonPath('data.closing_date', '2026-04-10')
        ->assertJsonPath('data.due_date', '2026-04-20');

    expect(CardStatement::count())->toBe(0);
});

it('prévia aponta para fatura existente', function () {
    $mar = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $this->getJson("/api/v1/cards/{$this->card->id}/statement-preview?date=2026-03-05")->assertJsonPath('data.statement_id', $mar->id);
});

it('prévia exige data válida', function () {
    $this->getJson("/api/v1/cards/{$this->card->id}/statement-preview")->assertUnprocessable()->assertJsonValidationErrors(['date']);
});
