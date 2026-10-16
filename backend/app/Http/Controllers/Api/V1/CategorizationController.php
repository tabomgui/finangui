<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Categorization\Actions\ApplyCategorization;
use App\Domain\Categorization\Queries\PendingCategorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categorization\ApplyCategorizationRequest;
use App\Http\Requests\Categorization\IndexPendingCategorizationRequest;
use App\Http\Resources\CategorizationApplyResource;
use App\Http\Resources\PendingCategorizationCountResource;
use App\Http\Resources\PendingCategorizationResource;

final class CategorizationController extends Controller
{
    public function pending(IndexPendingCategorizationRequest $request, PendingCategorization $query): PendingCategorizationResource
    {
        return PendingCategorizationResource::make(
            $query->pending($request->direction(), $request->search())
        );
    }

    public function pendingCount(PendingCategorization $query): PendingCategorizationCountResource
    {
        return PendingCategorizationCountResource::make($query->count());
    }

    public function apply(ApplyCategorizationRequest $request, ApplyCategorization $applyCategorization): CategorizationApplyResource
    {
        $validated = $request->validated();

        return CategorizationApplyResource::make($applyCategorization->handle(
            transactionIds: $validated['transaction_ids'],
            categoryId: $validated['category_id'],
            ruleInput: $validated['rule'] ?? null,
        ));
    }
}
