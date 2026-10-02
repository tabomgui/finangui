<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Models\User;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
});

function expense(array $overrides = []): array
{
    return ['account_id' => test()->card->id, 'date' => '2026-03-05', 'amount' => 1000, 'direction' => 'out', 'description' => 'Mercado', ...$overrides];
}

function dueOf(?int $statementId): ?string
{
    return $statementId === null ? null : CardStatement::query()->findOrFail($statementId)->due_date->toDateString();
}

it('despesa no cartão entra na fatura da data', function () {
    $id = $this->postJson('/api/v1/transactions', expense())->assertCreated()->json('data.statement_id');

    expect(dueOf($id))->toBe('2026-03-20');
});

it('lançamento em conta comum não tem fatura', function () {
    $this->postJson('/api/v1/transactions', expense(['account_id' => $this->checking->id]))
        ->assertCreated()->assertJsonPath('data.statement_id', null);
});

it('aceita fatura escolhida do próprio cartão', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->postJson('/api/v1/transactions', expense(['statement_id' => $apr->id]))
        ->assertCreated()->assertJsonPath('data.statement_id', $apr->id);
});

it('recusa fatura de outro cartão', function () {
    $other = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $other->id]);

    $this->postJson('/api/v1/transactions', expense(['statement_id' => $statement->id]))
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');
});

it('recusa fatura em conta comum', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id]);

    $this->postJson('/api/v1/transactions', expense(['account_id' => $this->checking->id, 'statement_id' => $statement->id]))
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');
});

it('não aceita fatura de outro usuário', function () {
    $statement = CardStatement::factory()->create(['account_id' => Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id])->id]);

    $this->postJson('/api/v1/transactions', expense(['statement_id' => $statement->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['statement_id']);
});

it('mudar a data para depois do fechamento muda a fatura', function () {
    $id = $this->postJson('/api/v1/transactions', expense())->json('data.id');

    $statementId = $this->patchJson("/api/v1/transactions/{$id}", ['date' => '2026-03-12'])->assertOk()->json('data.statement_id');

    expect(dueOf($statementId))->toBe('2026-04-20');
});

it('mover para conta comum tira a fatura', function () {
    $id = $this->postJson('/api/v1/transactions', expense())->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $this->checking->id])
        ->assertOk()->assertJsonPath('data.statement_id', null);
});

it('move o lançamento para outra fatura do cartão', function () {
    $id = $this->postJson('/api/v1/transactions', expense())->json('data.id');
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->patchJson("/api/v1/transactions/{$id}", ['statement_id' => $apr->id])->assertOk()->assertJsonPath('data.statement_id', $apr->id);
});

it('editar só a descrição mantém a fatura escolhida', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    $id = $this->postJson('/api/v1/transactions', expense(['statement_id' => $apr->id]))->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['description' => 'Outro'])->assertOk()->assertJsonPath('data.statement_id', $apr->id);
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

    expect(dueOf($response->json('data.from.statement_id')))->toBe('2026-03-20');
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

it('filtra transações por fatura', function () {
    $id = $this->postJson('/api/v1/transactions', expense())->json('data.statement_id');
    $this->postJson('/api/v1/transactions', expense(['date' => '2026-03-15']));

    $this->getJson("/api/v1/transactions?statement_id={$id}")->assertOk()->assertJsonCount(1, 'data');
});
