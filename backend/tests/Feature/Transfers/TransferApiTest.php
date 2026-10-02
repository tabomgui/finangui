<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;

function createTransfer(Account $from, Account $to, array $overrides = []): array
{
    return test()->postJson('/api/v1/transfers', array_merge([
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'date' => '2026-10-01',
        'amount' => 50000,
        'description' => 'Reserva',
    ], $overrides))->assertCreated()->json('data');
}

it('cria as duas pernas ligadas pelo mesmo transfer_id', function () {
    actingAsUser();
    $inter = Account::factory()->create();
    $nubank = Account::factory()->create();

    $transfer = createTransfer($inter, $nubank);

    expect($transfer['amount'])->toBe(50000)
        ->and($transfer['from']['account_id'])->toBe($inter->id)
        ->and($transfer['from']['direction'])->toBe('out')
        ->and($transfer['to']['account_id'])->toBe($nubank->id)
        ->and($transfer['to']['direction'])->toBe('in')
        ->and($transfer['from']['transfer_id'])->toBe($transfer['transfer_id'])
        ->and($transfer['to']['transfer_id'])->toBe($transfer['transfer_id'])
        ->and(Transaction::count())->toBe(2);
});

it('recusa transferência para a mesma conta', function () {
    actingAsUser();
    $inter = Account::factory()->create();

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $inter->id, 'to_account_id' => $inter->id, 'date' => '2026-10-01', 'amount' => 100, 'description' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
});

it('recusa transferência entre moedas diferentes', function () {
    actingAsUser();
    $brl = Account::factory()->create(['currency' => 'BRL']);
    $usd = Account::factory()->create(['currency' => 'USD']);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $brl->id, 'to_account_id' => $usd->id, 'date' => '2026-10-01', 'amount' => 100, 'description' => 'x',
    ])->assertStatus(409)->assertJsonPath('code', 'transfer_currency_mismatch');
});

it('edita valor e data nas duas pernas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['amount' => 75000, 'date' => '2026-10-02'])
        ->assertOk()
        ->assertJsonPath('data.amount', 75000)
        ->assertJsonPath('data.date', '2026-10-02');

    expect(Transaction::pluck('amount')->map->cents->all())->toBe([75000, 75000]);
});

it('troca a conta de origem', function () {
    actingAsUser();
    $to = Account::factory()->create();
    $transfer = createTransfer(Account::factory()->create(), $to);
    $newFrom = Account::factory()->create();

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['from_account_id' => $newFrom->id])
        ->assertOk()
        ->assertJsonPath('data.from.account_id', $newFrom->id);
});

it('recusa edição que deixa origem e destino iguais', function () {
    actingAsUser();
    $to = Account::factory()->create();
    $transfer = createTransfer(Account::factory()->create(), $to);

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['from_account_id' => $to->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_same_account');
});

it('exclui as duas pernas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->deleteJson("/api/v1/transfers/{$transfer['transfer_id']}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('excluir uma perna pelo endpoint de transações exclui as duas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->deleteJson("/api/v1/transactions/{$transfer['from']['id']}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('bloqueia mudar valor de uma perna pelo endpoint de transações', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->patchJson("/api/v1/transactions/{$transfer['from']['id']}", ['amount' => 1])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_leg_locked');

    $this->patchJson("/api/v1/transactions/{$transfer['from']['id']}", ['notes' => 'ok'])->assertOk();
});

it('retorna 404 para transferência inexistente', function () {
    actingAsUser();

    $this->patchJson('/api/v1/transfers/9b2f3c1e-0000-4000-8000-000000000000', ['amount' => 1])->assertNotFound();
});
