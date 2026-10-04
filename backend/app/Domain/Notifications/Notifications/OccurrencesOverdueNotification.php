<?php

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Support\DedupableNotification;
use Illuminate\Notifications\Notification;

/**
 * Ocorrências previstas de recorrência atrasadas (App\Domain\Recurrences\Queries\OverdueOccurrences),
 * uma notificação agregada por dia — ver App\Domain\Notifications\Jobs\SendAlerts, que também marca
 * como lida qualquer notificação deste tipo de um dia anterior ao criar a de hoje (o número de ontem
 * já não vale mais).
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
        return NotificationType::OccurrenceOverdue->value.":{$this->date}";
    }

    /**
     * @return array{type: NotificationType, key: string, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => NotificationType::OccurrenceOverdue,
            'key' => $this->dedupeKey(),
            'title' => 'Lançamentos pendentes',
            'body' => $this->wording(),
            'url' => '/',
        ];
    }

    private function wording(): string
    {
        return $this->count === 1
            ? '1 lançamento previsto não confirmado.'
            : "{$this->count} lançamentos previstos não confirmados.";
    }
}
