<?php

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Support\DedupableNotification;
use Illuminate\Notifications\Notification;

/**
 * Conexão bancária virou needs_reauth — ver
 * App\Domain\Banking\Jobs\SyncConnection::writeStatus(), que notifica só na
 * transição (o status anterior precisa ser active/error para o sync nem
 * chegar a reavaliar o item com o provedor).
 */
final class ConnectionNeedsReauthNotification extends Notification implements DedupableNotification
{
    public function __construct(
        private readonly int $connectionId,
        private readonly ?string $institutionName,
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
        return NotificationType::NeedsReauth->value.":{$this->connectionId}:{$this->date}";
    }

    /**
     * @return array{type: NotificationType, key: string, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $institution = $this->institutionName ?? 'banco';

        return [
            'type' => NotificationType::NeedsReauth,
            'key' => $this->dedupeKey(),
            'title' => 'Reconexão necessária',
            'body' => "O {$institution} pediu para reconectar.",
            'url' => '/contas',
        ];
    }
}
