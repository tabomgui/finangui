<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->string('direction', 3);
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('interval')->default(1);
            // Só faz sentido para frequency = monthly; padrão é o dia de starts_on.
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Até onde já foi gerado (GenerateOccurrences); null = nada ainda.
            $table->date('generated_until')->nullable();
            $table->string('match_pattern', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        DB::statement('ALTER TABLE recurrences ADD CONSTRAINT recurrences_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrences');
    }
};
