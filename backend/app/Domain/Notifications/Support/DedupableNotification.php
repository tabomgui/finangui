<?php

namespace App\Domain\Notifications\Support;

/**
 * Notificação cuja chave de deduplicação não depende de quem a recebe (só
 * dos próprios dados do alerta) — NotificationDeduper usa dedupeKey() para
 * decidir se já existe uma notificação igual antes de criar outra.
 */
interface DedupableNotification
{
    public function dedupeKey(): string;
}
