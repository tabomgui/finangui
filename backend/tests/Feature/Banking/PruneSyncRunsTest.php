<?php

use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Jobs\PruneSyncRuns;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankSyncRun;
use App\Domain\Banking\Models\BankSyncRunItem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

function oldRun(BankConnection $connection, int $daysAgo): BankSyncRun
{
    return BankSyncRun::factory()->success()->create([
        'connection_id' => $connection->id,
        'started_at' => now()->subDays($daysAgo),
        'finished_at' => now()->subDays($daysAgo),
    ]);
}

it('apaga runs com mais de 90 dias, mas mantém os 20 mais recentes de cada conexão mesmo assim', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);

    // 25 runs velhas (120 dias): só as 20 mais recentes sobrevivem.
    $old = collect(range(1, 25))->map(fn () => oldRun($connection, 120));

    (new PruneSyncRuns)->handle();

    expect(BankSyncRun::query()->withoutGlobalScopes()->where('connection_id', $connection->id)->count())->toBe(20);
});

it('nunca apaga uma run com 90 dias ou menos, mesmo acima do limite de 20', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);

    $recent = collect(range(1, 25))->map(fn () => oldRun($connection, 10));

    (new PruneSyncRuns)->handle();

    expect(BankSyncRun::query()->withoutGlobalScopes()->where('connection_id', $connection->id)->count())->toBe(25);
});

it('apaga os itens (BankSyncRunItem) da run apagada, via cascade', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);
    $old = oldRun($connection, 120);
    $item = BankSyncRunItem::factory()->create(['run_id' => $old->id]);

    // Mais 20 runs recentes para a antiga não sobreviver pela regra dos 20 mais recentes.
    collect(range(1, 20))->each(fn () => BankSyncRun::factory()->success()->create(['connection_id' => $connection->id]));

    (new PruneSyncRuns)->handle();

    expect(BankSyncRun::query()->withoutGlobalScopes()->whereKey($old->id)->exists())->toBeFalse()
        ->and(BankSyncRunItem::query()->withoutGlobalScopes()->whereKey($item->id)->exists())->toBeFalse();
});

it('percorre conexões de todos os usuários', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $connectionA = BankConnection::factory()->active()->create(['user_id' => $userA->id]);
    $connectionB = BankConnection::factory()->active()->create(['user_id' => $userB->id]);
    $oldA = oldRun($connectionA, 120);
    $oldB = oldRun($connectionB, 120);
    // Mais 20 runs recentes em cada conexão, para a antiga de cada uma não
    // sobreviver pela regra dos 20 mais recentes.
    collect(range(1, 20))->each(function () use ($connectionA, $connectionB) {
        BankSyncRun::factory()->success()->create(['connection_id' => $connectionA->id]);
        BankSyncRun::factory()->success()->create(['connection_id' => $connectionB->id]);
    });

    (new PruneSyncRuns)->handle();

    expect(BankSyncRun::query()->withoutGlobalScopes()->whereKey($oldA->id)->exists())->toBeFalse()
        ->and(BankSyncRun::query()->withoutGlobalScopes()->whereKey($oldB->id)->exists())->toBeFalse();
});

it('fecha como interrompida uma run running presa há mais de uniqueFor + margem', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);
    $stuck = BankSyncRun::factory()->create([
        'connection_id' => $connection->id,
        'started_at' => now()->subSeconds(SyncConnection::UNIQUE_FOR_SECONDS + 601),
    ]);

    (new PruneSyncRuns)->handle();

    expect($stuck->refresh()->status)->toBe(SyncRunStatus::Error)
        ->and($stuck->error)->toBe('Sincronização interrompida')
        ->and($stuck->finished_at)->not->toBeNull();
});

it('nunca fecha uma run running dentro da margem de uniqueFor', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id]);
    $running = BankSyncRun::factory()->create([
        'connection_id' => $connection->id,
        'started_at' => now()->subSeconds(SyncConnection::UNIQUE_FOR_SECONDS - 60),
    ]);

    (new PruneSyncRuns)->handle();

    expect($running->refresh()->status)->toBe(SyncRunStatus::Running);
});

it('roda fora de qualquer usuário autenticado (Auth::hasUser() volta a false)', function () {
    $user = User::factory()->create();
    BankConnection::factory()->active()->create(['user_id' => $user->id]);

    (new PruneSyncRuns)->handle();

    expect(Auth::hasUser())->toBeFalse();
});

it('está agendado para rodar diariamente', function () {
    app(Kernel::class)->bootstrap();

    $events = app(Schedule::class)->events();

    expect(collect($events)->contains(fn ($event) => $event->description === PruneSyncRuns::class))->toBeTrue();
});
