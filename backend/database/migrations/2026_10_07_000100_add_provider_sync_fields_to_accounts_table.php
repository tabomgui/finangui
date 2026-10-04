<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Piso de data para o sync bancário de uma conta manual vinculada
            // depois (ver App\Domain\Banking\Support\AccountMapper::linkExisting):
            // o que é anterior a esta data já está no saldo inicial do usuário.
            // Null numa conta criada pelo vínculo (sem limite — importa desde o
            // início, ver App\Domain\Banking\Jobs\SyncConnection).
            $table->date('provider_sync_from')->nullable();

            // Marca que o ajuste de abertura (opening_balance = saldo do banco
            // − transações lançadas) já foi feito, para rodar só uma vez — ver
            // App\Domain\Banking\Support\AccountMapper::settleOpeningBalance().
            $table->timestamp('provider_opening_set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['provider_sync_from', 'provider_opening_set_at']);
        });
    }
};
