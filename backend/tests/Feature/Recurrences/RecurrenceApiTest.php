<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

it('lista com ativas primeiro e depois por próxima data', function () {
    CarbonImmutable::setTestNow('2026-03-10');

    $later = Recurrence::factory()->create(['account_id' => $this->account->id, 'starts_on' => '2026-03-20', 'day_of_month' => 20]);
    $sooner = Recurrence::factory()->create(['account_id' => $this->account->id, 'starts_on' => '2026-03-12', 'day_of_month' => 12]);
    $paused = Recurrence::factory()->inactive()->create(['account_id' => $this->account->id, 'starts_on' => '2026-01-01', 'day_of_month' => 1]);

    app(GenerateOccurrences::class)->handle($later);
    app(GenerateOccurrences::class)->handle($sooner);

    $ids = $this->getJson('/api/v1/recurrences')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$sooner->id, $later->id, $paused->id]);
});

it('404 para recorrência de outro usuário em GET/PATCH/DELETE', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $recurrence = Recurrence::factory()->create(['account_id' => $otherAccount->id, 'user_id' => $other->id]);

    $this->getJson("/api/v1/recurrences/{$recurrence->id}")->assertNotFound();
    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['description' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/recurrences/{$recurrence->id}")->assertNotFound();
});

it('exclui o modelo: remove as previstas e mantém as já lançadas sem recurrence_id', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $posted = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->first();
    $posted->update(['status' => 'posted']);

    $this->deleteJson("/api/v1/recurrences/{$recurrence->id}")->assertNoContent();

    $posted->refresh();
    expect(Recurrence::query()->count())->toBe(0)
        ->and($posted->recurrence_id)->toBeNull()
        ->and($posted->recurrence_date)->toBeNull()
        ->and(Transaction::query()->where('status', 'projected')->count())->toBe(0);
});

it('404 para id não numérico nas rotas de recorrência', function () {
    $this->getJson('/api/v1/recurrences/abc')->assertNotFound();
    $this->patchJson('/api/v1/recurrences/abc', ['description' => 'x'])->assertNotFound();
    $this->deleteJson('/api/v1/recurrences/abc')->assertNotFound();
});
