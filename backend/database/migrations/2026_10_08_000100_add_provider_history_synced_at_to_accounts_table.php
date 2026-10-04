<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Null até o primeiro sync de histórico (365 dias, ou desde
            // provider_sync_from) desta conta ter sido buscado com sucesso;
            // depois disso, os syncs seguintes usam createdAtFrom (incremental)
            // — ver App\Domain\Banking\Jobs\SyncConnection.
            $table->timestamp('provider_history_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('provider_history_synced_at');
        });
    }
};
