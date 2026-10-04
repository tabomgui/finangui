<?php

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Support\DedupableNotification;
use Illuminate\Notifications\Notification;

/**
 * Ocorrências previstas de recorrência atrasadas (App\Domain\Recurrences\Queries\OverdueOccurrences),
 * uma notificação agregada por dia — ver App\Domain\Notifications\Jobs\SendAlerts.
 */
final class OccurrencesOverdueNotification extends Notification implements DedupableNotification
{
    public function __construct(
        private readonly int $count,
        private readonly string $date,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function dedupeKey(): string
    {
        return "occurrence_overdue:{$this->date}";
    }

    /**
     * @return array{type: string, key: string, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'occurrence_overdue',
            'key' => $this->dedupeKey(),
            'title' => 'Lançamentos pendentes',
            'body' => "{$this->count} lançamentos previstos não confirmados.",
            'url' => '/',
        ];
    }
}
