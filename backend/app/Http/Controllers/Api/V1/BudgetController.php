<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Budgets\Actions\DeleteBudget;
use App\Domain\Budgets\Actions\SaveBudget;
use App\Domain\Budgets\Queries\MonthBudget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\DeleteBudgetRequest;
use App\Http\Requests\Budgets\IndexBudgetsRequest;
use App\Http\Requests\Budgets\SaveBudgetRequest;
use App\Http\Resources\MonthBudgetResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

final class BudgetController extends Controller
{
    public function index(IndexBudgetsRequest $request, MonthBudget $monthBudget): MonthBudgetResource
    {
        return MonthBudgetResource::make($monthBudget->for($request->month()));
    }

    public function save(SaveBudgetRequest $request, SaveBudget $saveBudget, MonthBudget $monthBudget): MonthBudgetResource
    {
        $saveBudget->handle($request->validated());

        return MonthBudgetResource::make($monthBudget->for($this->responseMonth($request->validated())));
    }

    public function destroy(DeleteBudgetRequest $request, DeleteBudget $deleteBudget): Response
    {
        $deleteBudget->handle($request->validated());

        return response()->noContent();
    }

    /**
     * @param  array{category_id: int, amount?: int, month?: string}  $input  dados já validados
     */
    private function responseMonth(array $input): CarbonImmutable
    {
        return array_key_exists('month', $input)
            ? CarbonImmutable::createFromFormat('!Y-m', $input['month'])
            : CarbonImmutable::now()->startOfMonth();
    }
}
