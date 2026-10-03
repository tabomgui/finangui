<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
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
            $accountId = $plan->account_id;

            $plan->transactions()->delete();
            $plan->delete();

            // Trava o cartão antes de tocar as faturas: serializa com UpdateAccount,
            // que também reagenda/exclui faturas futuras ao mudar closing_day/due_day.
            Account::query()->whereKey($accountId)->lockForUpdate()->first();

            // As parcelas excluídas podem ter deixado faturas futuras vazias.
            CardStatement::pruneEmptyFuture($accountId);
        });
    }
}
