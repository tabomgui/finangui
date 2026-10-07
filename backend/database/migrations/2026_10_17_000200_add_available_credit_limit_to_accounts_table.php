<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Limite disponível informado pelo banco (creditData.availableCreditLimit
            // da Pluggy), em centavos — ver App\Domain\Banking\Support\AccountMapper.
            // Null quando a conta não é cartão ou o banco não informou.
            $table->bigInteger('available_credit_limit')->nullable()->after('credit_limit');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('available_credit_limit');
        });
    }
};
