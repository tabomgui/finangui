<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->bigInteger('total_amount');
            $table->unsignedSmallInteger('installments');
            $table->date('purchase_date');
            // Identifica a compra vinda do banco (data, total, número de parcelas): usado pela ingestão.
            $table->string('fingerprint')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'purchase_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_plans');
    }
};
