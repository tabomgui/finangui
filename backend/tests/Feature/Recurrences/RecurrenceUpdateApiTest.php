<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

it('PATCH de campo simples atualiza só as previstas futuras, não as passadas nem as lançadas', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    // Lança manualmente a prevista de março (passa a ser "posted").
    Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-03-05')
        ->update(['status' => 'posted']);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['description' => 'Aluguel novo'])
        ->assertOk()->assertJsonPath('data.description', 'Aluguel novo');

    $future = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-04-05')->first();
    $posted = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-03-05')->first();
    $past = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-02-05')->first();

    expect($future->description)->toBe('Aluguel novo')
        ->and($posted->description)->not->toBe('Aluguel novo')
        ->and($past->description)->not->toBe('Aluguel novo');
});

it('PATCH de calendário exclui as previstas futuras e gera de novo', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['day_of_month' => 20])
        ->assertOk()->assertJsonPath('data.day_of_month', 20);

    $dates = Transaction::query()->where('recurrence_id', $recurrence->id)->orderBy('recurrence_date')
        ->pluck('recurrence_date')->map(fn ($d) => $d->toDateString())->all();

    // 2026-03-05 não volta: cairia no mesmo período de 2026-03-20 (ver a janela de hoje - 5 dias).
    expect($dates)->toBe(['2026-01-05', '2026-02-05', '2026-03-20', '2026-04-20']);
});

it('PATCH day_of_month: null que resolve para o mesmo dia de starts_on não conta como mudança de calendário', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);
    $generatedUntilBefore = $recurrence->refresh()->generated_until->toDateString();

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['day_of_month' => null])
        ->assertOk()->assertJsonPath('data.day_of_month', 5);

    // Sem limpeza/regeneração: generated_until não volta para hoje - 6, e nada é excluído.
    expect($recurrence->refresh()->generated_until->toDateString())->toBe($generatedUntilBefore)
        ->and(Transaction::query()->where('recurrence_id', $recurrence->id)->count())->toBe(4);
});

it('PATCH de calendário também exclui previstas antigas que ficaram fora do novo intervalo', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    // Adiar starts_on para depois de janeiro: a prevista de janeiro, bem mais antiga que
    // hoje - 5 dias, deixa de existir no novo intervalo e precisa ser excluída também.
    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['starts_on' => '2026-02-10'])
        ->assertOk();

    expect(Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->exists())->toBeFalse();
});

it('PATCH de calendário não exclui ocorrência já lançada mesmo com recurrence_date >= hoje', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $future = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-04-05')->first();
    $future->update(['status' => 'posted']);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['day_of_month' => 20])->assertOk();

    expect(Transaction::query()->where('id', $future->id)->exists())->toBeTrue();
});

it('PATCH pausando exclui as previstas futuras; reativar gera de novo a partir de hoje', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    expect(Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '>=', '2026-03-10')->count())->toBe(0);

    CarbonImmutable::setTestNow('2026-03-15');
    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);

    $dates = Transaction::query()->where('recurrence_id', $recurrence->id)->orderBy('recurrence_date')
        ->pluck('recurrence_date')->map(fn ($d) => $d->toDateString())->all();

    // 2026-03-05 não volta: ficou dentro da janela de hoje - 5 dias quando pausou.
    expect($dates)->toBe(['2026-01-05', '2026-02-05', '2026-04-05']);
});

it('422 ao tentar mudar direction', function () {
    $recurrence = Recurrence::factory()->create(['account_id' => $this->account->id, 'direction' => Direction::Out]);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['direction' => 'in'])
        ->assertStatus(422)->assertJsonValidationErrors('direction');

    expect($recurrence->refresh()->direction)->toBe(Direction::Out);
});

it('422 ao editar match_pattern para algo sem nenhuma letra', function () {
    $recurrence = Recurrence::factory()->create(['account_id' => $this->account->id]);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['match_pattern' => '000'])
        ->assertStatus(422)->assertJsonValidationErrors('match_pattern');

    expect($recurrence->refresh()->match_pattern)->toBeNull();
});
