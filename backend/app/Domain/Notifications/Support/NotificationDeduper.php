<?php

namespace App\Domain\Notifications\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Evita notificação duplicada: só envia quando o usuário ainda não tem
 * nenhuma notificação com a mesma dedupeKey(). A checagem lê `data` (jsonb)
 * direto no banco — sem isso, cada chamada carregaria todas as notificações
 * do usuário só para comparar a chave em PHP.
 */
final class NotificationDeduper
{
    public function send(User $user, Notification&DedupableNotification $notification): void
    {
        if ($this->exists($user, $notification->dedupeKey())) {
            return;
        }

        $user->notify($notification);
    }

    private function exists(User $user, string $key): bool
    {
        return $user->notifications()
            ->whereRaw("data::jsonb ->> 'key' = ?", [$key])
            ->exists();
    }
}
