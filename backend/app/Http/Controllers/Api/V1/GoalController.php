<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Goals\Actions\RefreshGoalAchievement;
use App\Domain\Goals\Actions\UpdateGoal;
use App\Domain\Goals\Models\Goal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Goals\StoreGoalRequest;
use App\Http\Requests\Goals\UpdateGoalRequest;
use App\Http\Resources\GoalResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class GoalController extends Controller
{
    public function index(RefreshGoalAchievement $refreshGoalAchievement): AnonymousResourceCollection
    {
        $goals = $this->baseQuery()->orderByDesc('created_at')->get()
            ->each(fn (Goal $goal) => $refreshGoalAchievement->handle($goal));

        return GoalResource::collection($goals);
    }

    public function store(StoreGoalRequest $request, RefreshGoalAchievement $refreshGoalAchievement): JsonResponse
    {
        $goal = Goal::create($request->validated());
        $refreshGoalAchievement->handle($goal);

        return GoalResource::make($this->find($goal->id))->response()->setStatusCode(201);
    }

    public function show(Goal $goal, RefreshGoalAchievement $refreshGoalAchievement): GoalResource
    {
        $refreshGoalAchievement->handle($goal);

        return GoalResource::make($this->find($goal->id));
    }

    public function update(UpdateGoalRequest $request, Goal $goal, UpdateGoal $updateGoal, RefreshGoalAchievement $refreshGoalAchievement): GoalResource
    {
        $updateGoal->handle($goal, $request->validated());
        $refreshGoalAchievement->handle($goal);

        return GoalResource::make($this->find($goal->id));
    }

    public function destroy(Goal $goal): Response
    {
        $goal->delete();

        return response()->noContent();
    }

    /**
     * @return Builder<Goal>
     */
    private function baseQuery(): Builder
    {
        return Goal::query()
            ->with(['account' => fn ($query) => $query->withBalance()])
            ->withSum('contributions', 'amount');
    }

    private function find(int $id): Goal
    {
        return $this->baseQuery()->findOrFail($id);
    }
}
