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

it('mudar os dias do cartão não exclui fatura futura vazia com total informado ou external_id, mesmo sem lançamento', function () {
    $withReportedTotal = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-05-10', 'due_date' => '2026-05-20', 'reported_total' => 12345,
    ]);
    $withExternalId = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-06-10', 'due_date' => '2026-06-20', 'external_id' => 'ext-1',
    ]);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    expect(CardStatement::find($withReportedTotal->id))->not->toBeNull()
        ->and(CardStatement::find($withExternalId->id))->not->toBeNull();
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

it('pula a fatura cujo novo ciclo quebraria a ordem com a vizinha; a outra é reagendada mantendo a ordem', function () {
    // As duas no mesmo mês nominal (abril): a que fecha antes (dia 1) é processada primeiro e
    // seu novo ciclo (fecha 15) ficaria depois da vizinha seguinte (fecha 10, ainda no valor
    // antigo) — pulada, pra não inverter a ordem. A de fechamento 10, processada depois, acha
    // a vizinha anterior (a de cima, que ficou parada em 01/15) compatível e é reagendada.
    $earlier = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-15']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $earlier->id, 'amount' => 1000, 'direction' => 'out']);
    $later = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);
    Transaction::factory()->create(['account_id' => $this->card->id, 'statement_id' => $later->id, 'amount' => 1000, 'direction' => 'out']);

    $this->patchJson("/api/v1/accounts/{$this->card->id}", ['closing_day' => 15, 'due_day' => 25])->assertOk();

    $earlier->refresh();
    $later->refresh();
    expect($earlier->closing_date->toDateString())->toBe('2026-04-01')
        ->and($earlier->due_date->toDateString())->toBe('2026-04-15')
        ->and($later->closing_date->toDateString())->toBe('2026-04-15')
        ->and($later->due_date->toDateString())->toBe('2026-04-25');

    // Ordem preservada: fechamentos e vencimentos seguem crescentes entre as duas faturas.
    expect($earlier->closing_date->lessThan($later->closing_date))->toBeTrue()
        ->and($earlier->due_date->lessThan($later->due_date))->toBeTrue();
});

it('pula as duas faturas quando o novo ciclo da seguinte venceria antes da anterior, mesmo sem colisão exata', function () {
    // Cartão 25/20 (due_day < closing_day: vencimento cai no mês seguinte). Fatura atual fecha
    // 25/out, vence 20/nov; a seguinte fecha 25/nov, vence 20/dez. Hoje é 15/out.
    $card = Account::factory()->creditCard(closingDay: 25, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
    $current = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-10-25', 'due_date' => '2026-11-20']);
    Transaction::factory()->create(['account_id' => $card->id, 'statement_id' => $current->id, 'amount' => 1000, 'direction' => 'out']);
    $next = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-11-25', 'due_date' => '2026-12-20']);
    Transaction::factory()->create(['account_id' => $card->id, 'statement_id' => $next->id, 'amount' => 1000, 'direction' => 'out']);

    // Muda pra 1/10: o novo ciclo de outubro (fecha 01/out) já passou — a atual é pulada por
    // isso. O novo ciclo de novembro (fecha 01/nov, vence 10/nov) venceria ANTES do vencimento
    // da atual (20/nov), que ficou parada — pulada também, pra não inverter a ordem.
    $this->patchJson("/api/v1/accounts/{$card->id}", ['closing_day' => 1, 'due_day' => 10])->assertOk();

    $current->refresh();
    $next->refresh();
    expect($current->closing_date->toDateString())->toBe('2026-10-25')
        ->and($current->due_date->toDateString())->toBe('2026-11-20')
        ->and($next->closing_date->toDateString())->toBe('2026-11-25')
        ->and($next->due_date->toDateString())->toBe('2026-12-20');

    expect($current->closing_date->lessThan($next->closing_date))->toBeTrue()
        ->and($current->due_date->lessThan($next->due_date))->toBeTrue();
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
