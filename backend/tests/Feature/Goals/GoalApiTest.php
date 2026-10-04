<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Models\GoalContribution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    actingAsUser();
});

it('cria uma meta sem conta', function () {
    $this->postJson('/api/v1/goals', [
        'name' => 'Viagem',
        'target_amount' => 500000,
        'target_date' => now()->addMonths(5)->toDateString(),
        'color' => '#10b981',
        'icon' => 'plane',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Viagem')
        ->assertJsonPath('data.target_amount', 500000)
        ->assertJsonPath('data.progress', 0)
        ->assertJsonPath('data.percent', 0)
        ->assertJsonMissingPath('data.account');

    expect(Goal::query()->count())->toBe(1);
});

it('rejeita data alvo no passado na criação', function () {
    $this->postJson('/api/v1/goals', [
        'name' => 'Viagem', 'target_amount' => 500000, 'target_date' => now()->subDay()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('target_date');
});

it('rejeita conta de outro usuário', function () {
    actingAsUser();
    $otherAccount = Account::factory()->create();
    actingAsUser();

    $this->postJson('/api/v1/goals', ['name' => 'Carro', 'target_amount' => 300000, 'account_id' => $otherAccount->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_id');
});

it('rejeita conta de cartão de crédito, arquivada ou de outra moeda', function () {
    $creditCard = Account::factory()->creditCard()->create();
    $archived = Account::factory()->archived()->create();
    $usd = Account::factory()->create(['currency' => 'USD']);

    foreach ([$creditCard, $archived, $usd] as $account) {
        $this->postJson('/api/v1/goals', ['name' => 'Carro', 'target_amount' => 300000, 'account_id' => $account->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }
});

it('usa o saldo da conta vinculada como progresso', function () {
    $account = Account::factory()->create(['opening_balance' => 80000]);
    $goal = Goal::factory()->create(['account_id' => $account->id, 'target_amount' => 100000]);

    $this->getJson("/api/v1/goals/{$goal->id}")
        ->assertOk()
        ->assertJsonPath('data.account.id', $account->id)
        ->assertJsonPath('data.account.name', $account->name)
        ->assertJsonPath('data.progress', 80000)
        ->assertJsonPath('data.remaining', 20000)
        ->assertJsonPath('data.percent', 80);
});

it('zera o percentual quando a conta vinculada está no negativo, sem esconder o progresso real', function () {
    $account = Account::factory()->create(['opening_balance' => -5000]);
    $goal = Goal::factory()->create(['account_id' => $account->id, 'target_amount' => 100000]);

    $this->getJson("/api/v1/goals/{$goal->id}")
        ->assertJsonPath('data.progress', -5000)
        ->assertJsonPath('data.percent', 0)
        ->assertJsonPath('data.remaining', 105000);
});

it('soma os aportes quando a meta não tem conta', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 30000]);
    GoalContribution::factory()->for($goal)->create(['amount' => -5000]);

    $this->getJson("/api/v1/goals/{$goal->id}")
        ->assertJsonPath('data.progress', 25000)
        ->assertJsonMissingPath('data.account');
});

it('limita o percentual a 100 quando o progresso passa do alvo', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 150000]);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.percent', 100);
});

it('calcula o ritmo mensal e omite a chave quando não há data', function () {
    CarbonImmutable::setTestNow('2026-01-10');
    $withDate = Goal::factory()->create(['target_amount' => 90000, 'target_date' => '2026-04-01']);
    $withoutDate = Goal::factory()->create(['target_amount' => 90000, 'target_date' => null]);

    $this->getJson("/api/v1/goals/{$withDate->id}")->assertJsonPath('data.monthly_needed', 30000);
    $this->getJson("/api/v1/goals/{$withoutDate->id}")->assertJsonMissingPath('data.monthly_needed');
});

it('grava achieved_at quando o progresso atinge o alvo e nunca limpa depois', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 100000]);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.achieved_at', fn ($value) => $value !== null);

    $achievedAt = Goal::query()->findOrFail($goal->id)->achieved_at;

    GoalContribution::factory()->for($goal)->create(['amount' => -90000]);

    $this->getJson("/api/v1/goals/{$goal->id}")
        ->assertJsonPath('data.progress', 10000)
        ->assertJsonPath('data.achieved_at', $achievedAt->toIso8601String());
});

it('a listagem também atualiza achieved_at', function () {
    $goal = Goal::factory()->create(['target_amount' => 50000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 50000]);

    expect(Goal::query()->findOrFail($goal->id)->achieved_at)->toBeNull();

    $this->getJson('/api/v1/goals')->assertOk();

    expect(Goal::query()->findOrFail($goal->id)->achieved_at)->not->toBeNull();
});

it('continua funcionando quando a conta vinculada é arquivada ou excluída', function () {
    $account = Account::factory()->create(['opening_balance' => 40000]);
    $goal = Goal::factory()->create(['account_id' => $account->id, 'target_amount' => 100000]);

    $account->update(['is_archived' => true]);
    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.progress', 40000);

    $account->delete();

    $this->getJson("/api/v1/goals/{$goal->id}")
        ->assertJsonPath('data.account_id', null)
        ->assertJsonMissingPath('data.account')
        ->assertJsonPath('data.progress', 0);
});

it('atualiza uma meta', function () {
    $goal = Goal::factory()->create(['name' => 'Antigo', 'target_amount' => 100000]);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['name' => 'Novo', 'target_amount' => 200000])
        ->assertOk()
        ->assertJsonPath('data.name', 'Novo')
        ->assertJsonPath('data.target_amount', 200000);
});

it('aumentar o alvo acima do progresso desfaz achieved_at, só por mudar o alvo', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 100000]);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.achieved_at', fn ($value) => $value !== null);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['target_amount' => 150000])
        ->assertOk()
        ->assertJsonPath('data.achieved_at', null)
        ->assertJsonPath('data.percent', 66);
});

it('aumentar o alvo sem passar do progresso não desfaz achieved_at', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    GoalContribution::factory()->for($goal)->create(['amount' => 150000]);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.achieved_at', fn ($value) => $value !== null);

    $this->patchJson("/api/v1/goals/{$goal->id}", ['target_amount' => 120000])
        ->assertJsonPath('data.achieved_at', fn ($value) => $value !== null);
});

it('editar outro campo não desfaz achieved_at, mesmo que o progresso tenha caído', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    $contribution = GoalContribution::factory()->for($goal)->create(['amount' => 100000]);

    $this->getJson("/api/v1/goals/{$goal->id}")->assertJsonPath('data.achieved_at', fn ($value) => $value !== null);

    $contribution->delete();

    $this->patchJson("/api/v1/goals/{$goal->id}", ['name' => 'Novo nome'])
        ->assertJsonPath('data.achieved_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.progress', 0);
});

it('grava achieved_at sem tocar updated_at', function () {
    $goal = Goal::factory()->create(['target_amount' => 50000]);
    $updatedAtBefore = $goal->updated_at;

    GoalContribution::factory()->for($goal)->create(['amount' => 50000]);

    expect(Goal::query()->findOrFail($goal->id)->updated_at->equalTo($updatedAtBefore))->toBeTrue();
});

it('recusa vincular conta a uma meta que já tem aportes', function () {
    $goal = Goal::factory()->create();
    GoalContribution::factory()->for($goal)->create(['amount' => 10000]);
    $account = Account::factory()->create();

    $this->patchJson("/api/v1/goals/{$goal->id}", ['account_id' => $account->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'goal_has_contributions');
});

it('lista inclui a conta vinculada de cada meta', function () {
    $account = Account::factory()->create(['opening_balance' => 70000]);
    $goal = Goal::factory()->create(['account_id' => $account->id, 'target_amount' => 100000]);

    $this->getJson('/api/v1/goals')
        ->assertOk()
        ->assertJsonPath('data.0.account.id', $account->id)
        ->assertJsonPath('data.0.account.name', $account->name)
        ->assertJsonPath('data.0.progress', 70000);

    expect(Goal::query()->findOrFail($goal->id))->not->toBeNull();
});

it('lista metas sem uma consulta extra por meta para somar aportes', function () {
    $goals = Goal::factory()->count(3)->create(['target_amount' => 1000000]);
    foreach ($goals as $goal) {
        GoalContribution::factory()->for($goal)->create(['amount' => 1000]);
    }

    DB::enableQueryLog();
    $this->getJson('/api/v1/goals')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Uma consulta separada por meta aparece sem nunca mencionar "goals":
    // o withSum correto embute a soma como subconsulta dentro do próprio
    // select de goals, então a string da query principal cita as duas tabelas.
    $standaloneContributionQueries = array_filter(
        $queries,
        fn (array $entry) => str_contains($entry['query'], 'from "goal_contributions"') && ! str_contains($entry['query'], 'from "goals"'),
    );

    expect($standaloneContributionQueries)->toBe([]);
});

it('exclui uma meta', function () {
    $goal = Goal::factory()->create();

    $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();

    expect(Goal::query()->count())->toBe(0);
});

it('isola metas de outro usuário', function () {
    $goal = Goal::factory()->create();

    actingAsUser();

    $this->getJson("/api/v1/goals/{$goal->id}")->assertStatus(404);
    $this->getJson('/api/v1/goals')->assertJsonCount(0, 'data');
});
