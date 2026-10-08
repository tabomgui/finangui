<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_statements', function (Blueprint $table) {
            // Soma de payments[] da fatura do banco (GET /bills), em centavos —
            // gravado por App\Domain\Banking\Actions\SyncBills. Null quando o
            // banco não informa pagamentos para esta fatura (cartão manual, ou
            // sem payments[] disponível). Usado por App\Domain\Cards\Models\CardStatement::paid()
            // como piso: o maior entre os pagamentos locais e este valor, para
            // uma fatura de antes do histórico sincronizado (sem lançamento
            // local nenhum) ainda aparecer como paga quando o banco diz que foi.
            $table->bigInteger('reported_paid')->nullable()->after('reported_total');
        });
    }

    public function down(): void
    {
        Schema::table('card_statements', function (Blueprint $table) {
            $table->dropColumn('reported_paid');
        });
    }
};
