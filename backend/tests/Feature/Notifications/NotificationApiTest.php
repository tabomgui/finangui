<?php

use App\Domain\Notifications\Notifications\OccurrencesOverdueNotification;
use App\Models\User;

beforeEach(function () {
    $this->user = actingAsUser();
});

it('lista notificações paginadas por cursor, mais recentes primeiro, com unread_count', function () {
    $this->travelTo('2026-03-01 10:00:00');
    $this->user->notify(new OccurrencesOverdueNotification(1, '2026-03-01'));
    $this->travelTo('2026-03-02 10:00:00');
    $this->user->notify(new OccurrencesOverdueNotification(2, '2026-03-02'));

    $response = $this->getJson('/api/v1/notifications?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'occurrence_overdue')
        ->assertJsonPath('data.0.body', '2 lançamentos previstos não confirmados.')
        ->assertJsonPath('meta.unread_count', 2);

    $cursor = $response->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $this->getJson('/api/v1/notifications?per_page=1&cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonPath('data.0.body', '1 lançamento previsto não confirmado.')
        ->assertJsonPath('meta.next_cursor', null);
});

it('marca uma notificação como lida e isso reflete no unread_count', function () {
    $this->user->notify(new OccurrencesOverdueNotification(1, '2026-03-01'));
    $notification = $this->user->notifications()->first();

    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNoContent();

    expect($notification->refresh()->read_at)->not->toBeNull();
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
});

it('404 ao marcar como lida uma notificação de outro usuário', function () {
    $other = User::factory()->create();
    $other->notify(new OccurrencesOverdueNotification(1, '2026-03-01'));
    $notification = $other->notifications()->first();

    $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
});

it('marca todas as notificações do usuário como lidas', function () {
    $this->user->notify(new OccurrencesOverdueNotification(1, '2026-03-01'));
    $this->user->notify(new OccurrencesOverdueNotification(2, '2026-03-02'));

    $this->postJson('/api/v1/notifications/read-all')->assertNoContent();

    expect($this->user->unreadNotifications()->count())->toBe(0);
});
