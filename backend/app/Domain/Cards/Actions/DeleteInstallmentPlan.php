<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Cards\Models\InstallmentPlan;
use Illuminate\Support\Facades\DB;

final class DeleteInstallmentPlan
{
    /**
     * Exclui as N transações do plano e o plano em si; o pivot de tags vai
     * pela FK cascade do banco (ver create_transactions_table).
     */
    public function handle(InstallmentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $plan->transactions()->delete();
            $plan->delete();
        });
    }
}
