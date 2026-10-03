<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->bigInteger('credit_limit')->nullable()->after('opening_balance');
            $table->unsignedTinyInteger('closing_day')->nullable()->after('credit_limit');
            $table->unsignedTinyInteger('due_day')->nullable()->after('closing_day');
            $table->char('last_four', 4)->nullable()->after('due_day');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'closing_day', 'due_day', 'last_four']);
        });
    }
};
