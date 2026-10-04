<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

function recurrenceModel(array $overrides = []): Recurrence
{
    return Recurrence::factory()->create([
        'account_id' => test()->account->id,
        'frequency' => Frequency::Monthly,
        'interval' => 1,
        'day_of_month' => 5,
        'starts_on' => '2026-01-05',
        ...$overrides,
    ]);
}

function occurrenceDates(Recurrence $recurrence): array
{
    return Transaction::query()->where('recurrence_id', $recurrence->id)
        ->orderBy('recurrence_date')->pluck('recurrence_date')
        ->map(fn (CarbonImmutable $date) => $date->toDateString())->all();
}

it('gera as ocorrências até o fim do próximo mês', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel();

    $count = app(GenerateOccurrences::class)->handle($recurrence);

    expect($count)->toBe(4)
        ->and(occurrenceDates($recurrence))->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);

    $transaction = Transaction::query()->where('recurrence_date', '2026-04-05')->first();
    expect($transaction->status)->toBe(TransactionStatus::Projected)
        ->and($transaction->source)->toBe(TransactionSource::Recurrence)
        ->and($transaction->recurrence_id)->toBe($recurrence->id)
        ->and($transaction->amount->cents)->toBe($recurrence->amount->cents)
        ->and($recurrence->refresh()->generated_until->toDateString())->toBe('2026-04-30');
});

it('é idempotente: rodar duas vezes não duplica', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel();

    app(GenerateOccurrences::class)->handle($recurrence);
    $secondRunCount = app(GenerateOccurrences::class)->handle($recurrence);

    expect($secondRunCount)->toBe(0)
        ->and(occurrenceDates($recurrence))->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
});

it('recupera dias perdidos quando o job ficou parado', function () {
    CarbonImmutable::setTestNow('2026-01-10');
    $recurrence = recurrenceModel();
    app(GenerateOccurrences::class)->handle($recurrence);

    CarbonImmutable::setTestNow('2026-04-10');
    app(GenerateOccurrences::class)->handle($recurrence);

    expect(occurrenceDates($recurrence))->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05']);
});

it('ocorrência pulada (excluída) com data já gerada nunca volta', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel();
    app(GenerateOccurrences::class)->handle($recurrence);

    Transaction::query()->where('recurrence_date', '2026-02-05')->delete();

    app(GenerateOccurrences::class)->handle($recurrence);

    expect(occurrenceDates($recurrence))->toBe(['2026-01-05', '2026-03-05', '2026-04-05']);
});

it('recorrência inativa não gera nada', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel(['is_active' => false]);

    $count = app(GenerateOccurrences::class)->handle($recurrence);

    expect($count)->toBe(0)
        ->and(occurrenceDates($recurrence))->toBe([])
        ->and($recurrence->refresh()->generated_until)->toBeNull();
});

it('conta arquivada não gera nada', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $this->account->update(['is_archived' => true]);
    $recurrence = recurrenceModel();

    $count = app(GenerateOccurrences::class)->handle($recurrence);

    expect($count)->toBe(0)
        ->and(occurrenceDates($recurrence))->toBe([])
        ->and($recurrence->refresh()->generated_until)->toBeNull();
});

it('respeita ends_on: não gera depois do fim', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel(['ends_on' => '2026-02-20']);

    app(GenerateOccurrences::class)->handle($recurrence);

    expect(occurrenceDates($recurrence))->toBe(['2026-01-05', '2026-02-05'])
        ->and($recurrence->refresh()->generated_until->toDateString())->toBe('2026-02-20');
});

it('recorrência em cartão de crédito ganha fatura e nunca prevê data anterior a hoje', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $recurrence = recurrenceModel(['account_id' => $card->id]);

    app(GenerateOccurrences::class)->handle($recurrence);

    // 01-05, 02-05 e 03-05 são antes de hoje (cartão não prevê o passado); só 04-05 é gerada.
    expect(occurrenceDates($recurrence))->toBe(['2026-04-05']);

    $transaction = Transaction::query()->where('recurrence_date', '2026-04-05')->first();
    expect($transaction->statement_id)->not->toBeNull();
});

it('não toca em transação de outro usuário', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceModel();

    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);
    $otherTransaction = Transaction::query()->withoutGlobalScopes()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
        'date' => '2026-03-05',
        'amount' => 999,
        'direction' => 'out',
        'currency' => 'BRL',
        'description' => 'Outro usuário',
        'original_description' => 'Outro usuário',
    ]);

    app(GenerateOccurrences::class)->handle($recurrence);

    expect(Transaction::query()->withoutGlobalScopes()->findOrFail($otherTransaction->id)->recurrence_id)->toBeNull();
});
