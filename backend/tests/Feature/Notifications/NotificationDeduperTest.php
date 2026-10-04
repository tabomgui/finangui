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
