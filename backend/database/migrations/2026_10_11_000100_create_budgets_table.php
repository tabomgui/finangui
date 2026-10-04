<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            // Nulo = padrão mensal, valendo para todo mês sem exceção própria.
            $table->date('month')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'month']);
        });

        // Padrão único por categoria: índice parcial, porque a constraint acima
        // não bloqueia duas linhas com month nulo (NULL nunca é igual a NULL).
        DB::statement('CREATE UNIQUE INDEX budgets_category_default_unique ON budgets (category_id) WHERE month IS NULL');
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
