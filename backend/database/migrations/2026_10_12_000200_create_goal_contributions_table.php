<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            // Negativo = retirada; nunca zero.
            $table->bigInteger('amount');
            $table->date('date');
            $table->string('note', 120)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE goal_contributions ADD CONSTRAINT goal_contributions_amount_not_zero CHECK (amount <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_contributions');
    }
};
