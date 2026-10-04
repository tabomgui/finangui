<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

function occurrenceRecurrence(array $overrides = []): Recurrence
{
    return Recurrence::factory()->create(array_merge([
        'account_id' => test()->account->id,
        'day_of_month' => 5,
        'starts_on' => '2026-01-05',
    ], $overrides));
}

it('lista só as previstas com mais de 5 dias de atraso', function () {
    // Gera 2026-01-05, 2026-02-05, 2026-03-05 e 2026-04-05.
    CarbonImmutable::setTestNow('2026-03-01');
    $recurrence = occurrenceRecurrence();
    app(GenerateOccurrences::class)->handle($recurrence);

    // hoje - 5 dias = 2026-02-07: 01-05 e 02-05 estão atrasadas; 03-05 e 04-05 não.
    CarbonImmutable::setTestNow('2026-02-12');

    $data = $this->getJson('/api/v1/recurrences/overdue')->assertOk()->json('data');
    $dates = array_column($data, 'date');

    expect($dates)->toBe(['2026-01-05', '2026-02-05']);
    foreach ($data as $occurrence) {
        expect($occurrence)->toHaveKey('recurrence');
        expect($occurrence['recurrence']['id'])->toBe($recurrence->id);
    }
});

it('não lista previstas recentes nem lançadas nem de outro usuário', function () {
    CarbonImmutable::setTestNow('2026-01-01');
    $recurrence = occurrenceRecurrence();
    app(GenerateOccurrences::class)->handle($recurrence);

    CarbonImmutable::setTestNow('2026-01-06');
    // hoje - 5 dias = 2026-01-01: 2026-01-05 não está atrasada ainda (não é < 2026-01-01).
    $data = $this->getJson('/api/v1/recurrences/overdue')->assertOk()->json('data');
    expect($data)->toBe([]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    UserContext::run($other, function () use ($otherAccount, $other) {
        $otherRecurrence = Recurrence::factory()->create([
            'account_id' => $otherAccount->id, 'user_id' => $other->id,
            'starts_on' => '2025-01-05', 'day_of_month' => 5,
        ]);
        app(GenerateOccurrences::class)->handle($otherRecurrence);
    });

    $data = $this->getJson('/api/v1/recurrences/overdue')->assertOk()->json('data');
    expect($data)->toBe([]);
});

it('confirma uma ocorrência com os valores previstos por padrão', function () {
    CarbonImmutable::setTestNow('2026-02-20');
    $recurrence = occurrenceRecurrence();
    app(GenerateOccurrences::class)->handle($recurrence);
    $occurrence = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->firstOrFail();

    $data = $this->postJson("/api/v1/recurrences/occurrences/{$occurrence->id}/confirm")
        ->assertOk()->json('data');

    expect($data['status'])->toBe('posted')
        ->and($data['amount'])->toBe($recurrence->amount->cents)
        ->and($data['date'])->toBe('2026-01-05')
        ->and($data['recurrence']['id'])->toBe($recurrence->id);
});

it('confirma uma ocorrência com valor e data informados', function () {
    CarbonImmutable::setTestNow('2026-02-20');
    $recurrence = occurrenceRecurrence();
    app(GenerateOccurrences::class)->handle($recurrence);
    $occurrence = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->firstOrFail();

    $data = $this->postJson("/api/v1/recurrences/occurrences/{$occurrence->id}/confirm", [
        'amount' => 160000,
        'date' => '2026-01-06',
    ])->assertOk()->json('data');

    expect($data['status'])->toBe('posted')
        ->and($data['amount'])->toBe(160000)
        ->and($data['date'])->toBe('2026-01-06');

    // recurrence_date (a identidade da ocorrência) continua a original.
    expect($occurrence->refresh()->recurrence_date->toDateString())->toBe('2026-01-05');
});

it('pula uma ocorrência: exclui e nunca mais volta', function () {
    CarbonImmutable::setTestNow('2026-02-20');
    $recurrence = occurrenceRecurrence();
    app(GenerateOccurrences::class)->handle($recurrence);
    $occurrence = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->firstOrFail();

    $this->postJson("/api/v1/recurrences/occurrences/{$occurrence->id}/skip")->assertNoContent();

    expect(Transaction::query()->where('id', $occurrence->id)->exists())->toBeFalse();

    app(GenerateOccurrences::class)->handle($recurrence);
    expect(Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->exists())->toBeFalse();
});

it('409 ao confirmar ou pular um lançamento que não é mais previsto', function () {
    $posted = Transaction::factory()->create(['account_id' => $this->account->id, 'status' => TransactionStatus::Posted]);

    $this->postJson("/api/v1/recurrences/occurrences/{$posted->id}/confirm")
        ->assertStatus(409)->assertJsonPath('code', 'occurrence_not_projected');

    $this->postJson("/api/v1/recurrences/occurrences/{$posted->id}/skip")
        ->assertStatus(409)->assertJsonPath('code', 'occurrence_not_projected');
});

it('404 ao confirmar ou pular um lançamento de outro usuário', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $transaction = Transaction::factory()->create(['account_id' => $otherAccount->id, 'user_id' => $other->id]);

    $this->postJson("/api/v1/recurrences/occurrences/{$transaction->id}/confirm")->assertNotFound();
    $this->postJson("/api/v1/recurrences/occurrences/{$transaction->id}/skip")->assertNotFound();
});
