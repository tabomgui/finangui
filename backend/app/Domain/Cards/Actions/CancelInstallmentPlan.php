<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\TransactionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Cancela o restante de um parcelamento (ex.: produto devolvido, quitação
 * combinada): exclui as parcelas projetadas e mantém as já lançadas.
 */
final class CancelInstallmentPlan
{
    public function handle(InstallmentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            // Trava a linha do plano: dois cancelamentos simultâneos não
            // passam juntos (o segundo acha cancelled_at já preenchido).
            $locked = InstallmentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            // Uma query só: o pivot de tags vai pela FK cascade do banco.
            $locked->transactions()->where('status', TransactionStatus::Projected->value)->delete();

            $locked->cancelled_at ??= now()->toImmutable();
            $locked->save();

            // Trava o cartão antes de tocar as faturas: serializa com UpdateAccount,
            // que também reagenda/exclui faturas futuras ao mudar closing_day/due_day.
            Account::query()->whereKey($locked->account_id)->lockForUpdate()->first();

            // As parcelas projetadas excluídas podem ter deixado faturas futuras vazias.
            CardStatement::pruneEmptyFuture($locked->account_id);
        });
    }
}
