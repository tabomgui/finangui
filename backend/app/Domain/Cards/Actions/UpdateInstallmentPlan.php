<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\TransactionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Descrição e categoria do parcelamento valem para as parcelas ainda
 * projetadas; as já lançadas ficam como estão (podem ser editadas uma a uma).
 */
final class UpdateInstallmentPlan
{
    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     */
    public function handle(InstallmentPlan $plan, array $input): InstallmentPlan
    {
        DB::transaction(function () use ($plan, $input) {
            $changes = [];
            if (array_key_exists('description', $input)) {
                $plan->description = $input['description'];
                $changes['description'] = $input['description'];
                $changes['description_locked'] = true;
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
