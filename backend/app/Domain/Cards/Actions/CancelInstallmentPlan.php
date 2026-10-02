<?php

namespace App\Domain\Cards\Actions;

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
            $plan->transactions()->where('status', TransactionStatus::Projected->value)->each(fn ($parcel) => $parcel->delete());
            $plan->cancelled_at ??= now()->toImmutable();
            $plan->save();
        });
    }
}
