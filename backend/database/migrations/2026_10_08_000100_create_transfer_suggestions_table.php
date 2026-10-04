<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('out_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('in_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->decimal('score', 4, 3);
            $table->string('status', 12)->default('pending');
            $table->timestamps();

            $table->unique(['out_transaction_id', 'in_transaction_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_suggestions');
    }
};
