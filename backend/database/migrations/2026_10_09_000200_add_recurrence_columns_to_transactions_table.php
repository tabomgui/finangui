<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->date('recurrence_date')->nullable();
            $table->unique(['recurrence_id', 'recurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['recurrence_id', 'recurrence_date']);
            $table->dropConstrainedForeignId('recurrence_id');
            $table->dropColumn('recurrence_date');
        });
    }
};
