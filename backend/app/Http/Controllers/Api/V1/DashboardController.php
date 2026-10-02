<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\Queries\MonthSummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, MonthSummary $monthSummary): JsonResponse
    {
        return response()->json(['data' => $monthSummary->for($request->month())]);
    }
}
