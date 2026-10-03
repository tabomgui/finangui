<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->startOfDay());
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $this->statement->id, 'amount' => 10000]);
});

function pay(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/card-statements/'.test()->statement->id.'/payments', [
        'from_account_id' => test()->checking->id, 'amount' => 10000, 'date' => '2026-03-15', ...$overrides,
    ]);
}

it('paga a fatura com uma transferência ligada a ela', function () {
    pay()->assertCreated()
        ->assertJsonPath('data.from.account_id', $this->checking->id)
        ->assertJsonPath('data.to.account_id', $this->card->id)
        ->assertJsonPath('data.to.statement_id', $this->statement->id)
        ->assertJsonPath('data.description', 'Pagamento de fatura');

    $this->getJson("/api/v1/card-statements/{$this->statement->id}")
        ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.remaining', 0);
});

it('pagamento parcial deixa a fatura parcial', function () {
    pay(['amount' => 4000])->assertCreated();

    $this->getJson("/api/v1/card-statements/{$this->statement->id}")
        ->assertJsonPath('data.status', 'partial')->assertJsonPath('data.remaining', 6000);
});

it('liga à fatura escolhida mesmo pagando antes do fechamento de outra', function () {
    $apr = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $apr->id, 'amount' => 500]);

    $this->postJson("/api/v1/card-statements/{$apr->id}/payments", ['from_account_id' => $this->checking->id, 'amount' => 500, 'date' => '2026-03-15'])
        ->assertCreated()->assertJsonPath('data.to.statement_id', $apr->id);
});

it('recusa pagar fatura já quitada', function () {
    pay()->assertCreated();

    pay()->assertStatus(409)->assertJsonPath('code', 'statement_already_paid');
});

it('recusa pagar com o próprio cartão', function () {
    pay(['from_account_id' => $this->card->id])->assertStatus(409)->assertJsonPath('code', 'transfer_same_account');
});

it('valida conta de origem do usuário, valor e data', function () {
    $other = Account::factory()->create(['user_id' => User::factory()->create()->id]);

    pay(['from_account_id' => $other->id, 'amount' => 0, 'date' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['from_account_id', 'amount', 'date']);
});

it('recusa pagar com conta de moeda diferente', function () {
    $usd = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'USD']);

    pay(['from_account_id' => $usd->id])->assertStatus(409)->assertJsonPath('code', 'transfer_currency_mismatch');
});

it('fatura aberta pode ser paga mesmo com total zero', function () {
    $open = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->postJson("/api/v1/card-statements/{$open->id}/payments", ['from_account_id' => $this->checking->id, 'amount' => 1000, 'date' => '2026-03-15'])
        ->assertCreated()->assertJsonPath('data.to.statement_id', $open->id);

    $this->getJson("/api/v1/card-statements/{$open->id}")
        ->assertJsonPath('data.status', 'open')->assertJsonPath('data.paid', 1000);
});

it('pagamento maior que o restante deixa saldo credor sem bloquear', function () {
    pay(['amount' => 15000])->assertCreated();

    $this->getJson("/api/v1/card-statements/{$this->statement->id}")
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.paid', 15000)
        ->assertJsonPath('data.remaining', 0);
});

it('aceita pagar a fatura com outro cartão como origem', function () {
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    pay(['from_account_id' => $otherCard->id])->assertCreated()
        ->assertJsonPath('data.from.account_id', $otherCard->id)
        ->assertJsonPath('data.to.statement_id', $this->statement->id);
});
