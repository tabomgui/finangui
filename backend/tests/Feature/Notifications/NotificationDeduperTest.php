<?php

use App\Domain\Notifications\Notifications\OccurrencesOverdueNotification;
use App\Domain\Notifications\Support\NotificationDeduper;
use App\Models\User;

it('não envia de novo quando já existe notificação com a mesma key', function () {
    $user = User::factory()->create();
    $deduper = new NotificationDeduper;

    $deduper->send($user, new OccurrencesOverdueNotification(3, '2026-03-01'));
    $deduper->send($user, new OccurrencesOverdueNotification(3, '2026-03-01'));

    expect($user->notifications()->count())->toBe(1);
});

it('envia normalmente quando a key é diferente', function () {
    $user = User::factory()->create();
    $deduper = new NotificationDeduper;

    $deduper->send($user, new OccurrencesOverdueNotification(3, '2026-03-01'));
    $deduper->send($user, new OccurrencesOverdueNotification(5, '2026-03-02'));

    expect($user->notifications()->count())->toBe(2);
});

it('a mesma key para usuários diferentes ainda é enviada para os dois', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $deduper = new NotificationDeduper;

    $deduper->send($a, new OccurrencesOverdueNotification(3, '2026-03-01'));
    $deduper->send($b, new OccurrencesOverdueNotification(3, '2026-03-01'));

    expect($a->notifications()->count())->toBe(1)
        ->and($b->notifications()->count())->toBe(1);
});

it('não duplica mesmo quando a pré-checagem erra: o índice único do banco protege a corrida', function () {
    $user = User::factory()->create();
    $user->notify(new OccurrencesOverdueNotification(3, '2026-03-01'));

    // Simula a corrida entre a checagem (exists()) e o insert: aqui ela
    // mente que não existe, para a tentativa chegar de fato no insert e
    // provar que quem garante a deduplicação é o índice único do banco
    // (migration add_dedup_unique_index_to_notifications_table), não só a
    // checagem em PHP.
    $deduper = new class extends NotificationDeduper
    {
        protected function exists(User $user, string $key): bool
        {
            return false;
        }
    };

    $deduper->send($user, new OccurrencesOverdueNotification(3, '2026-03-01'));

    expect($user->notifications()->count())->toBe(1);
});
