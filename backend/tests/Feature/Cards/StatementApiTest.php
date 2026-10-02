<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->startOfDay());
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);
});

it('mostra a fatura com totais e divergência', function () {
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $this->statement->id, 'amount' => 1000]);
    $this->statement->update(['reported_total' => 1500]);

    $this->getJson("/api/v1/card-statements/{$this->statement->id}")->assertOk()
        ->assertJsonPath('data.total', 1000)
        ->assertJsonPath('data.reported_total', 1500)
        ->assertJsonPath('data.has_divergence', true)
        ->assertJsonPath('data.status', 'closed');
});

it('edita datas e total informado', function () {
    $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-03-09', 'due_date' => '2026-03-19', 'reported_total' => 999])
        ->assertOk()->assertJsonPath('data.closing_date', '2026-03-09')->assertJsonPath('data.due_date', '2026-03-19')->assertJsonPath('data.reported_total', 999);
});

it('limpa o total informado com null', function () {
    $this->statement->update(['reported_total' => 1]);

    $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['reported_total' => null])->assertOk()->assertJsonPath('data.reported_total', null);
});

it('fechamento precisa ser antes do vencimento, considerando o valor atual', function () {
    $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-03-25'])
        ->assertUnprocessable()->assertJsonValidationErrors(['closing_date']);
    $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['due_date' => '2026-03-05'])
        ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
});

it('vencimento não pode repetir o de outra fatura do cartão', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['due_date' => '2026-04-20'])
        ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
});

it('fatura de outro usuário dá 404', function () {
    $other = CardStatement::factory()->create(['account_id' => Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id])->id]);

    $this->getJson("/api/v1/card-statements/{$other->id}")->assertNotFound();
    $this->patchJson("/api/v1/card-statements/{$other->id}", ['reported_total' => 1])->assertNotFound();
});

// Ajuste: a edição de datas precisa manter as faturas do cartão ordenadas e
// sem sobreposição em relação às vizinhas por closing_date (não só à atual).
describe('ordenação com as faturas vizinhas', function () {
    beforeEach(function () {
        $this->previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);
        $this->next = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    });

    it('fechamento precisa ficar estritamente entre o fechamento das vizinhas', function () {
        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-02-05'])
            ->assertUnprocessable()->assertJsonValidationErrors(['closing_date']);

        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-02-10'])
            ->assertUnprocessable()->assertJsonValidationErrors(['closing_date']);

        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-04-15', 'due_date' => '2026-04-25'])
            ->assertUnprocessable()->assertJsonValidationErrors(['closing_date']);
    });

    it('vencimento precisa ficar estritamente entre o vencimento das vizinhas', function () {
        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['due_date' => '2026-04-25'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
    });

    it('vencimento não pode passar de 40 dias depois do fechamento', function () {
        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-03-01', 'due_date' => '2026-04-15'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
    });

    it('edita dentro dos limites das vizinhas sem problema', function () {
        $this->patchJson("/api/v1/card-statements/{$this->statement->id}", ['closing_date' => '2026-03-12', 'due_date' => '2026-03-22'])
            ->assertOk()
            ->assertJsonPath('data.closing_date', '2026-03-12')
            ->assertJsonPath('data.due_date', '2026-03-22');
    });
});
