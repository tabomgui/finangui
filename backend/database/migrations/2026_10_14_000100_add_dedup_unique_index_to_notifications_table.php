<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Garante a deduplicação por (notifiable, key) no próprio banco, não só
     * na checagem em PHP: duas tentativas de criar a mesma notificação ao
     * mesmo tempo (ex.: o job reprocessado por engano) só conseguem
     * inserir uma — App\Domain\Notifications\Support\NotificationDeduper
     * trata a violação da tentativa perdedora como no-op.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX notifications_notifiable_dedup_key_unique
            ON notifications (notifiable_type, notifiable_id, ((data::jsonb ->> 'key')))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_dedup_key_unique');
    }
};
