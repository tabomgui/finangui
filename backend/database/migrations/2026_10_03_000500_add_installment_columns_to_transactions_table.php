<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('installment_plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->index('installment_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installment_plan_id');
            $table->dropColumn('installment_number');
        });
    }
};
