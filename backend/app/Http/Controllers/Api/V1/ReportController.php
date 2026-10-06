<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\Queries\CategoryComparison;
use App\Domain\Reports\Queries\MonthlyEvolution;
use App\Domain\Reports\Queries\SpendingBreakdown;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CategoryComparisonRequest;
use App\Http\Requests\Reports\MonthlyEvolutionRequest;
use App\Http\Requests\Reports\SpendingBreakdownRequest;
use App\Http\Resources\CategoryComparisonResource;
use App\Http\Resources\MonthlyEvolutionResource;
use App\Http\Resources\SpendingBreakdownResource;

final class ReportController extends Controller
{
    public function monthly(MonthlyEvolutionRequest $request, MonthlyEvolution $monthlyEvolution): MonthlyEvolutionResource
    {
        return MonthlyEvolutionResource::make(
            $monthlyEvolution->for($request->from(), $request->to(), $request->basis())
        );
    }

    public function categories(CategoryComparisonRequest $request, CategoryComparison $categoryComparison): CategoryComparisonResource
    {
        return CategoryComparisonResource::make(
            $categoryComparison->for($request->aFrom(), $request->aTo(), $request->bFrom(), $request->bTo(), $request->basis())
        );
    }

    public function spending(SpendingBreakdownRequest $request, SpendingBreakdown $spendingBreakdown): SpendingBreakdownResource
    {
        return SpendingBreakdownResource::make($spendingBreakdown->for($request->from(), $request->to()));
    }
}
