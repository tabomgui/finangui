<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->bigInteger('amount');
            $table->string('direction', 3);
            $table->char('currency', 3);
            $table->string('description');
            $table->string('original_description');
            $table->boolean('description_locked')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee', 120)->nullable();
            $table->string('status', 10)->default('posted');
            $table->string('source', 12)->default('manual');
            $table->string('external_id')->nullable();
            $table->string('categorized_by', 32)->nullable();
            $table->boolean('is_ignored')->default(false);
            $table->uuid('transfer_id')->nullable()->index();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date', 'id']);
            $table->unique(['account_id', 'external_id']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_positive CHECK (amount > 0)');

        Schema::create('tag_transaction', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_transaction');
        Schema::dropIfExists('transactions');
    }
};
