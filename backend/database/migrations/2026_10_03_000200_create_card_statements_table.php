<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Conta só é excluída sem lançamentos; as faturas vazias vão junto.
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('closing_date');
            $table->date('due_date');
            $table->string('external_id')->nullable();
            $table->bigInteger('reported_total')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'due_date']);
            $table->unique(['account_id', 'external_id']);
            $table->index(['account_id', 'closing_date']);
        });

        DB::statement('ALTER TABLE card_statements ADD CONSTRAINT card_statements_dates_order CHECK (closing_date < due_date AND due_date <= closing_date + 40)');
    }

    public function down(): void
    {
        Schema::dropIfExists('card_statements');
    }
};
