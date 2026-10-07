<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // true assim que o usuário escolhe a fatura à mão (`statement_id`
            // no PATCH de transação — ver App\Domain\Transactions\Actions\UpdateTransaction):
            // a sincronização bancária (App\Domain\Imports\Actions\MatchedTransactionOutcomes::update())
            // nunca mais move statement_id por conta própria a partir do
            // bill_id do banco para esta transação.
            $table->boolean('statement_locked')->default(false)->after('card_payment_locked');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('statement_locked');
        });
    }
};
