<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Models\User;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
});

function cardExpensePayload(array $overrides = []): array
{
    return ['account_id' => test()->card->id, 'date' => '2026-03-05', 'amount' => 1000, 'direction' => 'out', 'description' => 'Mercado', ...$overrides];
}

function statementDueDate(?int $statementId): ?string
{
    return $statementId === null ? null : CardStatement::query()->findOrFail($statementId)->due_date->toDateString();
}

it('despesa no cartão entra na fatura da data', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->assertCreated()->json('data.statement_id');

    expect(statementDueDate($id))->toBe('2026-03-20');
});

it('lançamento em conta comum não tem fatura', function () {
    $this->postJson('/api/v1/transactions', cardExpensePayload(['account_id' => $this->checking->id]))
        ->assertCreated()->assertJsonPath('data.statement_id', null);
});

it('aceita fatura escolhida do próprio cartão', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->postJson('/api/v1/transactions', cardExpensePayload(['statement_id' => $apr->id]))
        ->assertCreated()->assertJsonPath('data.statement_id', $apr->id);
});

it('recusa fatura de outro cartão', function () {
    $other = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $other->id]);

    $this->postJson('/api/v1/transactions', cardExpensePayload(['statement_id' => $statement->id]))
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');
});

it('recusa fatura em conta comum', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id]);

    $this->postJson('/api/v1/transactions', cardExpensePayload(['account_id' => $this->checking->id, 'statement_id' => $statement->id]))
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');
});

it('não aceita fatura de outro usuário', function () {
    $statement = CardStatement::factory()->create(['account_id' => Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id])->id]);

    $this->postJson('/api/v1/transactions', cardExpensePayload(['statement_id' => $statement->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['statement_id']);
});

it('mudar a data para depois do fechamento muda a fatura', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->json('data.id');

    $statementId = $this->patchJson("/api/v1/transactions/{$id}", ['date' => '2026-03-12'])->assertOk()->json('data.statement_id');

    expect(statementDueDate($statementId))->toBe('2026-04-20');
});

it('mover para conta comum tira a fatura', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $this->checking->id])
        ->assertOk()->assertJsonPath('data.statement_id', null);
});

it('move o lançamento para outra fatura do cartão', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->json('data.id');
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->patchJson("/api/v1/transactions/{$id}", ['statement_id' => $apr->id])->assertOk()->assertJsonPath('data.statement_id', $apr->id);
});

it('editar só a descrição mantém a fatura escolhida', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload(['statement_id' => $apr->id]))->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['description' => 'Outro'])->assertOk()->assertJsonPath('data.statement_id', $apr->id);
});

it('reenviar a mesma conta e data mantém a fatura escolhida', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload(['statement_id' => $apr->id]))->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $this->card->id, 'date' => '2026-03-05'])
        ->assertOk()->assertJsonPath('data.statement_id', $apr->id);
});

it('statement_id e account_id no mesmo PATCH: fatura do cartão antigo dá 409, do novo dá 200', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->json('data.id');
    $oldStatementId = CardStatement::query()->where('account_id', $this->card->id)->first()->id;
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $otherStatement = CardStatement::factory()->create(['account_id' => $otherCard->id]);

    $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $otherCard->id, 'statement_id' => $oldStatementId])
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');

    $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $otherCard->id, 'statement_id' => $otherStatement->id])
        ->assertOk()->assertJsonPath('data.statement_id', $otherStatement->id);
});

it('transferência para o cartão liga a entrada à última fatura fechada', function () {
    $feb = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);

    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-02-15', 'amount' => 5000, 'description' => 'Pagamento',
    ])->assertCreated();

    expect($response->json('data.to.statement_id'))->toBe($feb->id)
        ->and($response->json('data.from.statement_id'))->toBeNull();
});

it('transferência a partir do cartão entra na fatura da data', function () {
    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->card->id, 'to_account_id' => $this->checking->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Saque',
    ])->assertCreated();

    expect(statementDueDate($response->json('data.from.statement_id')))->toBe('2026-03-20');
});

it('editar a transferência trocando o destino recalcula a fatura', function () {
    $transferId = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Pagamento',
    ])->json('data.transfer_id');
    $savings = Account::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/v1/transfers/{$transferId}", ['to_account_id' => $savings->id])
        ->assertOk()->assertJsonPath('data.to.statement_id', null);
});

it('editar a data de um pagamento mantém a fatura escolhida', function () {
    $mar = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-03', 'due_date' => '2026-03-10']);
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-04', 'due_date' => '2026-04-10']);

    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-03-25', 'amount' => 5000, 'description' => 'Pagamento',
    ])->assertCreated();
    expect($response->json('data.to.statement_id'))->toBe($mar->id);

    $transferId = $response->json('data.transfer_id');
    $inId = $response->json('data.to.id');
    $this->patchJson("/api/v1/transactions/{$inId}", ['statement_id' => $apr->id])->assertOk();

    $this->patchJson("/api/v1/transfers/{$transferId}", ['date' => '2026-03-26'])
        ->assertOk()->assertJsonPath('data.to.statement_id', $apr->id);
});

it('editar a data de uma transferência a partir do cartão recalcula a fatura da saída', function () {
    $transferId = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->card->id, 'to_account_id' => $this->checking->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Saque',
    ])->json('data.transfer_id');

    $response = $this->patchJson("/api/v1/transfers/{$transferId}", ['date' => '2026-03-12'])->assertOk();

    expect(statementDueDate($response->json('data.from.statement_id')))->toBe('2026-04-20');
});

it('permite escolher a fatura da entrada de um pagamento pelo endpoint de transações', function () {
    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Pagamento',
    ])->json('data');
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->patchJson("/api/v1/transactions/{$response['to']['id']}", ['statement_id' => $apr->id])
        ->assertOk()->assertJsonPath('data.statement_id', $apr->id);
});

it('recusa fatura na perna de fora do cartão pelo endpoint de transações', function () {
    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Pagamento',
    ])->json('data');
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id]);

    $this->patchJson("/api/v1/transactions/{$response['from']['id']}", ['statement_id' => $statement->id])
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');
});

it('mudar campo travado junto com a fatura numa perna de transferência trava', function () {
    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->checking->id, 'to_account_id' => $this->card->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Pagamento',
    ])->json('data');
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->patchJson("/api/v1/transactions/{$response['to']['id']}", ['amount' => 9999, 'statement_id' => $apr->id])
        ->assertStatus(409)->assertJsonPath('code', 'transfer_leg_locked');
});

it('transferência entre dois cartões liga cada perna à fatura certa', function () {
    $otherCard = Account::factory()->creditCard(closingDay: 5, dueDay: 15)->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->card->id, 'to_account_id' => $otherCard->id,
        'date' => '2026-03-05', 'amount' => 5000, 'description' => 'Pagamento entre cartões',
    ])->assertCreated();

    $fromStatement = CardStatement::query()->findOrFail($response->json('data.from.statement_id'));
    $toStatement = CardStatement::query()->findOrFail($response->json('data.to.statement_id'));

    expect($fromStatement->account_id)->toBe($this->card->id)
        ->and($toStatement->account_id)->toBe($otherCard->id);
});

it('filtra transações por fatura', function () {
    $id = $this->postJson('/api/v1/transactions', cardExpensePayload())->json('data.statement_id');
    $this->postJson('/api/v1/transactions', cardExpensePayload(['date' => '2026-03-15']));

    $this->getJson("/api/v1/transactions?statement_id={$id}")->assertOk()->assertJsonCount(1, 'data');
});

it('filtrar por fatura de outro usuário não retorna nada', function () {
    $this->postJson('/api/v1/transactions', cardExpensePayload());
    $otherUser = User::factory()->create();
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $otherUser->id]);
    $otherStatement = CardStatement::factory()->create(['account_id' => $otherCard->id]);

    $this->getJson("/api/v1/transactions?statement_id={$otherStatement->id}")->assertOk()->assertJsonCount(0, 'data');
});
