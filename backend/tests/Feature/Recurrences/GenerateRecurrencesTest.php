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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

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

it('isola a falha de uma recorrência: as outras (do mesmo usuário ou de outro) continuam gerando', function () {
    Exceptions::fake();
    CarbonImmutable::setTestNow('2026-03-10');
    $user = User::factory()->create();
    $broken = recurrenceFor($user);
    $sameUser = recurrenceFor($user);
    $otherUser = recurrenceFor(User::factory()->create());

    // Valor fora do enum, só possível gravando direto na coluna (um dado
    // assim nunca entra pelo Eloquent): ao reler a recorrência sob
    // lockForUpdate, o cast de frequency lança ao hidratar o model.
    DB::table('recurrences')->where('id', $broken->id)->update(['frequency' => 'invalid']);

    (new GenerateRecurrences)->handle();

    $count = fn (Recurrence $r) => Transaction::query()->withoutGlobalScopes()->where('recurrence_id', $r->id)->count();
    expect($count($broken))->toBe(0)
        ->and($count($sameUser))->toBe(4)
        ->and($count($otherUser))->toBe(4);

    Exceptions::assertReportedCount(1);
});

it('está agendado para rodar diariamente', function () {
    app(Kernel::class)->bootstrap();

    $events = app(Schedule::class)->events();

    expect(collect($events)->contains(fn ($event) => $event->description === GenerateRecurrences::class))->toBeTrue();
});
