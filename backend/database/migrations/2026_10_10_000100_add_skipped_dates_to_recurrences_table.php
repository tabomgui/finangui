<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurrences', function (Blueprint $table) {
            // Datas puladas (SkipOccurrence): a geração nunca mais recria uma
            // ocorrência nessas datas, mesmo depois de mudança de calendário
            // ou pausa/reativação.
            $table->jsonb('skipped_dates')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('recurrences', function (Blueprint $table) {
            $table->dropColumn('skipped_dates');
        });
    }
};
