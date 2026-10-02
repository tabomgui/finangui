<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Cards\Models\InstallmentPlan;
use Illuminate\Support\Facades\DB;

final class DeleteInstallmentPlan
{
    public function handle(InstallmentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $plan->transactions()->each(fn ($parcel) => $parcel->delete());
            $plan->delete();
        });
    }
}
