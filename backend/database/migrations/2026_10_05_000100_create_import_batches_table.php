<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('format', 20);
            $table->string('source', 10);
            $table->string('filename', 255);
            $table->string('status', 12)->default('pending');
            $table->jsonb('rows')->nullable();
            $table->jsonb('stats')->nullable();
            $table->jsonb('undo')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index('account_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->index('import_batch_id');
        });

        Schema::table('installment_plans', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });

        Schema::dropIfExists('import_batches');
    }
};
