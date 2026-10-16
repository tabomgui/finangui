<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Categorization\Queries\PendingCategorization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Categorization\IndexPendingCategorizationRequest;
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
}
