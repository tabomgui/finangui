<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Jobs\GenerateRecurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

function recurrenceFor(User $user, array $overrides = []): Recurrence
{
    $account = Account::factory()->create(['user_id' => $user->id]);

    return Recurrence::factory()->create([
        'account_id' => $account->id,
        'frequency' => Frequency::Monthly,
        'interval' => 1,
        'day_of_month' => 5,
        'starts_on' => '2026-01-05',
        ...$overrides,
    ]);
}

it('gera as ocorrências das recorrências ativas de todos os usuários', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $a = recurrenceFor(User::factory()->create());
    $b = recurrenceFor(User::factory()->create());
    $inactive = recurrenceFor(User::factory()->create(), ['is_active' => false]);

    (new GenerateRecurrences)->handle();

    $count = fn (Recurrence $r) => Transaction::query()->withoutGlobalScopes()->where('recurrence_id', $r->id)->count();
    expect($count($a))->toBe(4)
        ->and($count($b))->toBe(4)
        ->and($count($inactive))->toBe(0)
        ->and(Auth::hasUser())->toBeFalse();
});

it('é idempotente: rodar de novo não duplica', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = recurrenceFor(User::factory()->create());

    (new GenerateRecurrences)->handle();
    (new GenerateRecurrences)->handle();

    expect(Transaction::query()->withoutGlobalScopes()->where('recurrence_id', $recurrence->id)->count())->toBe(4);
});

it('está agendado para rodar diariamente', function () {
    app(Kernel::class)->bootstrap();

    $events = app(Schedule::class)->events();

    expect(collect($events)->contains(fn ($event) => $event->description === GenerateRecurrences::class))->toBeTrue();
});
