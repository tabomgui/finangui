<?php

namespace App\Domain\Notifications\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Evita notificação duplicada: só envia quando o usuário ainda não tem
 * nenhuma notificação com a mesma dedupeKey(). A checagem lê `data` (jsonb)
 * direto no banco — sem isso, cada chamada carregaria todas as notificações
 * do usuário só para comparar a chave em PHP.
 *
 * Essa checagem por si só tem uma corrida (checa, depois insere): o índice
 * único da migration add_dedup_unique_index_to_notifications_table (mesma
 * expressão `data::jsonb ->> 'key'`, por notifiable) é quem garante de
 * verdade; send() só precisa tratar a violação da tentativa perdedora como
 * no-op, nunca deixar o job falhar por isso.
 */
/**
 * Não é final de propósito: o teste da corrida entre exists() e o insert
 * (NotificationDeduperTest) precisa de uma subclasse que force exists() a
 * mentir, para provar que quem garante a deduplicação de verdade é o índice
 * único do banco, não a checagem em PHP.
 */
class NotificationDeduper
{
    public function send(User $user, Notification&DedupableNotification $notification): void
    {
        if ($this->exists($user, $notification->dedupeKey())) {
            return;
        }

        try {
            DB::transaction(fn () => $user->notify($notification));
        } catch (UniqueConstraintViolationException) {
            // Savepoint (DB::transaction, aninhada numa transação externa se
            // houver uma): a violação não contamina nada além deste insert.
        }
    }

    protected function exists(User $user, string $key): bool
    {
        return $user->notifications()
            ->whereRaw("data::jsonb ->> 'key' = ?", [$key])
            ->exists();
    }
}
