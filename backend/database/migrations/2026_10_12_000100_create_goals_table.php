<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->bigInteger('target_amount');
            $table->date('target_date')->nullable();
            // Conta excluída: a meta volta a somar aportes (sum de goal_contributions),
            // nunca é apagada junto.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            // Gravado na primeira vez que o progresso atinge o alvo; nunca limpo depois
            // (ver App\Domain\Goals\Actions\RefreshGoalAchievement).
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE goals ADD CONSTRAINT goals_target_amount_positive CHECK (target_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
