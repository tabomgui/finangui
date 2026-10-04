<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Goals\Actions\CreateGoalContribution;
use App\Domain\Goals\Actions\DeleteGoalContribution;
use App\Domain\Goals\Models\Goal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Goals\StoreGoalContributionRequest;
use App\Http\Resources\GoalContributionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class GoalContributionController extends Controller
{
    public function index(Goal $goal): AnonymousResourceCollection
    {
        $contributions = $goal->contributions()->orderByDesc('date')->orderByDesc('id')->get();

        return GoalContributionResource::collection($contributions);
    }

    public function store(StoreGoalContributionRequest $request, Goal $goal, CreateGoalContribution $createGoalContribution): JsonResponse
    {
        $contribution = $createGoalContribution->handle($goal, $request->validated());

        return GoalContributionResource::make($contribution)->response()->setStatusCode(201);
    }

    /**
     * $contribution não é vinculado por model binding de propósito: precisa
     * ser buscado dentro de $goal->contributions() para que um aporte de uma
     * meta não possa ser apagado através da URL de outra (as duas podem ser
     * do mesmo usuário e passariam pelo escopo de BelongsToUser sem isso).
     */
    public function destroy(Goal $goal, int $contribution, DeleteGoalContribution $deleteGoalContribution): Response
    {
        $model = $goal->contributions()->findOrFail($contribution);
        $deleteGoalContribution->handle($model);

        return response()->noContent();
    }
}
