<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Uma exceção de mês com amount = 0 cancela o orçamento daquele mês
     * (ver SaveBudget/SaveBudgetRequest); o padrão mensal continua exigindo
     * amount > 0 — aplicado na validação, não mais no banco.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE budgets DROP CONSTRAINT budgets_amount_positive');
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_non_negative CHECK (amount >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE budgets DROP CONSTRAINT budgets_amount_non_negative');
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budgets_amount_positive CHECK (amount > 0)');
    }
};
