<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Cards\Errors\InstallmentPlanFinished;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\TransactionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Descrição e categoria do parcelamento valem para as parcelas ainda
 * projetadas; as já lançadas ficam como estão (podem ser editadas uma a uma).
 * Categoria exige parcelas projetadas (parcelamento com todas as parcelas já
 * lançadas não tem onde aplicá-la); descrição continua editável mesmo assim.
 */
final class UpdateInstallmentPlan
{
    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     *
     * @throws InstallmentPlanFinished
     */
    public function handle(InstallmentPlan $plan, array $input): InstallmentPlan
    {
        DB::transaction(function () use ($plan, $input) {
            $hasProjected = $plan->transactions()->where('status', TransactionStatus::Projected->value)->exists();

            if (array_key_exists('category_id', $input) && ! $hasProjected) {
                throw new InstallmentPlanFinished;
            }

            $changes = [];
            if (array_key_exists('description', $input) && $input['description'] !== $plan->description) {
                $plan->description = $input['description'];
                $changes['description'] = $input['description'];
                $changes['description_locked'] = true;
            } elseif (array_key_exists('description', $input)) {
                $plan->description = $input['description'];
            }
            if (array_key_exists('category_id', $input)) {
                $categoryId = $input['category_id'] === null ? null : (int) $input['category_id'];
                $changes['category_id'] = $categoryId;
                $changes['categorized_by'] = $categoryId === null ? null : 'manual';
            }

            $plan->save();
            if ($changes !== []) {
                $plan->transactions()->where('status', TransactionStatus::Projected->value)->update($changes);
            }
        });

        return $plan;
    }
}
