<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Liga o casamento da categoria do banco por nome como terceiro
            // passo da categorização na sincronização (depois de regras e
            // histórico — ver App\Domain\Imports\Actions\IngestTransactions).
            // Desligado para todo usuário, inclusive os que já existem: quem
            // categoriza é o finangui, não o banco.
            $table->boolean('use_provider_categories')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('use_provider_categories');
        });
    }
};
