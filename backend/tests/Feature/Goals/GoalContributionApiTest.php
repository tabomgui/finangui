<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Models\GoalContribution;

beforeEach(function () {
    actingAsUser();
});

it('registra um aporte numa meta sem conta', function () {
    $goal = Goal::factory()->create();

    $this->postJson("/api/v1/goals/{$goal->id}/contributions", [
        'amount' => 20000, 'date' => '2026-01-10', 'note' => 'Bônus',
    ])
        ->assertCreated()
        ->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.note', 'Bônus');

    expect(GoalContribution::query()->where('goal_id', $goal->id)->count())->toBe(1);
});

it('aceita retirada com valor negativo', function () {
    $goal = Goal::factory()->create();
    GoalContribution::factory()->for($goal)->create(['amount' => 50000]);

    $this->postJson("/api/v1/goals/{$goal->id}/contributions", ['amount' => -20000, 'date' => '2026-01-11'])
        ->assertCreated()
        ->assertJsonPath('data.amount', -20000);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.progress', 30000);
});

it('rejeita aporte com valor zero', function () {
    $goal = Goal::factory()->create();

    $this->postJson("/api/v1/goals/{$goal->id}/contributions", ['amount' => 0, 'date' => '2026-01-11'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('rejeita aporte numa meta com conta vinculada', function () {
    $account = Account::factory()->create();
    $goal = Goal::factory()->create(['account_id' => $account->id]);

    $this->postJson("/api/v1/goals/{$goal->id}/contributions", ['amount' => 10000, 'date' => '2026-01-11'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'goal_contributions_not_allowed');

    expect(GoalContribution::query()->count())->toBe(0);
});

it('lista os aportes de uma meta, mais recentes primeiro', function () {
    $goal = Goal::factory()->create();
    GoalContribution::factory()->for($goal)->create(['date' => '2026-01-01', 'amount' => 1000]);
    GoalContribution::factory()->for($goal)->create(['date' => '2026-01-15', 'amount' => 2000]);

    $this->getJson("/api/v1/goals/{$goal->id}/contributions")
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-01-15')
        ->assertJsonPath('data.1.date', '2026-01-01');
});

it('exclui um aporte', function () {
    $goal = Goal::factory()->create();
    $contribution = GoalContribution::factory()->for($goal)->create();

    $this->deleteJson("/api/v1/goals/{$goal->id}/contributions/{$contribution->id}")->assertNoContent();

    expect(GoalContribution::query()->count())->toBe(0);
});

it('não deixa excluir um aporte através da url de outra meta', function () {
    $goalA = Goal::factory()->create();
    $goalB = Goal::factory()->create();
    $contribution = GoalContribution::factory()->for($goalA)->create();

    $this->deleteJson("/api/v1/goals/{$goalB->id}/contributions/{$contribution->id}")->assertStatus(404);

    expect(GoalContribution::query()->count())->toBe(1);
});

it('isola aportes de metas de outro usuário', function () {
    $goal = Goal::factory()->create();
    GoalContribution::factory()->for($goal)->create();

    actingAsUser();

    $this->getJson("/api/v1/goals/{$goal->id}/contributions")->assertStatus(404);
    $this->postJson("/api/v1/goals/{$goal->id}/contributions", ['amount' => 1000, 'date' => '2026-01-01'])->assertStatus(404);
});
