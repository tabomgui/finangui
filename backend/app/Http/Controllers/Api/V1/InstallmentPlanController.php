<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cards\Actions\CancelInstallmentPlan;
use App\Domain\Cards\Actions\UpdateInstallmentPlan;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Cards\Queries\InstallmentPlanList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\UpdateInstallmentPlanRequest;
use App\Http\Resources\InstallmentPlanResource;
use Illuminate\Http\Response;

final class InstallmentPlanController extends Controller
{
    public function update(UpdateInstallmentPlanRequest $request, InstallmentPlan $plan, UpdateInstallmentPlan $updatePlan, InstallmentPlanList $list): InstallmentPlanResource
    {
        $updatePlan->handle($plan, $request->validated());

        return InstallmentPlanResource::make($list->find($plan->id));
    }

    public function destroy(InstallmentPlan $plan, CancelInstallmentPlan $cancelPlan): Response
    {
        $cancelPlan->handle($plan);

        return response()->noContent();
    }
}
