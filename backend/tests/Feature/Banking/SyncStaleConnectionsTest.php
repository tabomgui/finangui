<?php

use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Jobs\SyncStaleConnections;
use App\Domain\Banking\Models\BankConnection;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

it('despacha SyncConnection só para conexões active/error vencidas (nunca sincronizadas ou há 4h ou mais)', function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));

    $user = User::factory()->create();
    $neverSynced = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'active', 'last_synced_at' => null]);
    $stale = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'active', 'last_synced_at' => now()->subHours(4)]);
    $staleError = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'error', 'last_error' => 'x', 'last_synced_at' => now()->subHours(5)]);
    $fresh = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'active', 'last_synced_at' => now()->subHours(1)]);
    $freshError = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'error', 'last_error' => 'x', 'last_synced_at' => now()->subHours(1)]);
    $needsReauth = BankConnection::factory()->needsReauth()->create(['user_id' => $user->id]);
    $pendingLink = BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'pending_link']);

    (new SyncStaleConnections)->handle();

    Queue::assertPushed(SyncConnection::class, 3);
    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->trigger === SyncTrigger::Scheduled);
    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $neverSynced->id);
    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $stale->id);
    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $staleError->id);
    Queue::assertNotPushed(SyncConnection::class, fn (SyncConnection $job) => in_array($job->connectionId, [$fresh->id, $freshError->id, $needsReauth->id, $pendingLink->id], true));
});

it('percorre conexões de todos os usuários', function () {
    Queue::fake();

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $a = BankConnection::factory()->create(['user_id' => $userA->id, 'status' => 'active', 'last_synced_at' => null]);
    $b = BankConnection::factory()->create(['user_id' => $userB->id, 'status' => 'active', 'last_synced_at' => null]);

    (new SyncStaleConnections)->handle();

    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $a->id);
    Queue::assertPushed(SyncConnection::class, fn (SyncConnection $job) => $job->connectionId === $b->id);
});

it('roda fora de qualquer usuário autenticado (Auth::hasUser() volta a false)', function () {
    Queue::fake();
    $user = User::factory()->create();
    BankConnection::factory()->create(['user_id' => $user->id, 'status' => 'active', 'last_synced_at' => null]);

    (new SyncStaleConnections)->handle();

    expect(Auth::hasUser())->toBeFalse();
});

it('está agendado para rodar a cada 6 horas', function () {
    app(Kernel::class)->bootstrap();

    $events = app(Schedule::class)->events();

    expect(collect($events)->contains(fn ($event) => $event->description === SyncStaleConnections::class))->toBeTrue();
});
