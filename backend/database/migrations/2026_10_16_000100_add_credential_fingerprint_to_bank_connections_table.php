<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            // Null para toda conexão existente (criada sob as credenciais
            // globais antigas, por variável de ambiente, ou ainda não
            // sincronizada desde que a credencial por usuário passou a
            // existir) — ver App\Domain\Banking\Models\BankCredential::fingerprint()
            // e App\Domain\Banking\Jobs\SyncConnection.
            $table->string('credential_fingerprint')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_connections', function (Blueprint $table) {
            $table->dropColumn('credential_fingerprint');
        });
    }
};
