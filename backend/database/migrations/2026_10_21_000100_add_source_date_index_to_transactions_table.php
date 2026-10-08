<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Acelera o correlacionado de App\Domain\Cards\Models\CardStatement::scopeWithTotals()
            // que acha a transação source=pluggy mais antiga de cada conta
            // (first_synced_date, usado por historyIncompleteSince()).
            $table->index(['account_id', 'source', 'date'], 'transactions_account_source_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_source_date_index');
        });
    }
};
