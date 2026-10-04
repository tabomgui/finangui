<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\Queries\CategoryComparison;
use App\Domain\Reports\Queries\MonthlyEvolution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CategoryComparisonRequest;
use App\Http\Requests\Reports\MonthlyEvolutionRequest;
use App\Http\Resources\CategoryComparisonResource;
use App\Http\Resources\MonthlyEvolutionResource;

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
}
