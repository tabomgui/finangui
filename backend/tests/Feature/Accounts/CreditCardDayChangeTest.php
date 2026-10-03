<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 6)->startOfDay());
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
});

it('mudar os dias do cartão exclui as faturas futuras que não têm lançamento', function () {
    $empty = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-10', 'due_date' => '2026-05-20']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    expect(CardStatement::find($empty->id))->toBeNull();
});

it('mudar os dias do cartão reagenda as faturas futuras com lançamento para o novo ciclo nominal do mesmo mês', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $statement->id, 'amount' => 1000, 'direction' => 'out']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    $statement->refresh();
    expect($statement->closing_date->toDateString())->toBe('2026-04-15')
        ->and($statement->due_date->toDateString())->toBe('2026-04-25');
});

it('não reagenda a fatura quando o novo fechamento cairia hoje ou antes: mantém as datas antigas', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-09', 'due_date' => '2026-03-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $statement->id, 'amount' => 1000, 'direction' => 'out']);

    // Novo closing_day=1: o novo ciclo nominal de março (dia 1) já passou (hoje é 2026-03-06).
    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 1, 'due_day' => 20])->assertOk();

    $statement->refresh();
    expect($statement->closing_date->toDateString())->toBe('2026-03-09')
        ->and($statement->due_date->toDateString())->toBe('2026-03-20');
});

it('pula a fatura cujo novo vencimento colidiria com o de outra fatura; a outra é reagendada normalmente', function () {
    // As duas no mesmo mês nominal (abril): quem fecha antes (dia 1) reivindica o novo ciclo de abril
    // (fecha 15, vence 25); a de fechamento 10, processada depois, encontra o vencimento já tomado e é pulada.
    $earlier = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-15']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $earlier->id, 'amount' => 1000, 'direction' => 'out']);
    $later = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $later->id, 'amount' => 1000, 'direction' => 'out']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    $earlier->refresh();
    $later->refresh();
    expect($earlier->closing_date->toDateString())->toBe('2026-04-15')
        ->and($earlier->due_date->toDateString())->toBe('2026-04-25')
        ->and($later->closing_date->toDateString())->toBe('2026-04-10')
        ->and($later->due_date->toDateString())->toBe('2026-04-20');
});

it('não toca faturas passadas, mesmo vazias', function () {
    $past = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    expect(CardStatement::find($past->id))->not->toBeNull();
    $past->refresh();
    expect($past->closing_date->toDateString())->toBe('2026-02-10');
});

it('não reagenda nada quando só outros campos de cartão mudam', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $statement->id, 'amount' => 1000, 'direction' => 'out']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['credit_limit' => 900000])->assertOk();

    $statement->refresh();
    expect($statement->closing_date->toDateString())->toBe('2026-04-10');
});

it('compra depois de mudar o dia de fechamento cai no novo ciclo', function () {
    // Fatura vazia de março, criada pelo ciclo antigo (fecha dia 10); muda-se o dia de
    // fechamento para 20 antes de qualquer compra — a fatura antiga (vazia) é excluída, então
    // a compra de 15/03 resolve certo pro novo ciclo (fecha 20), não fica perdida atrás dela.
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 20, 'due_day' => 28])->assertOk();

    $id = $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-03-15', 'amount' => 1000, 'direction' => 'out', 'description' => 'Compra',
    ])->json('data.id');

    $statement = CardStatement::find(Transaction::find($id)->statement_id);
    expect($statement->closing_date->toDateString())->toBe('2026-03-20')
        ->and($statement->due_date->toDateString())->toBe('2026-03-28');
});
