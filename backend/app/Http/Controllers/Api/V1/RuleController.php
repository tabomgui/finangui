<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Rules\Actions\CreateRule;
use App\Domain\Rules\Actions\ReorderRules;
use App\Domain\Rules\Actions\UpdateRule;
use App\Domain\Rules\Models\Rule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\ReorderRulesRequest;
use App\Http\Requests\Rules\StoreRuleRequest;
use App\Http\Requests\Rules\UpdateRuleRequest;
use App\Http\Resources\RuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class RuleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RuleResource::collection(Rule::query()->ordered()->get());
    }

    public function store(StoreRuleRequest $request, CreateRule $createRule): JsonResponse
    {
        return RuleResource::make($createRule->handle($request->validated()))->response()->setStatusCode(201);
    }

    public function show(Rule $rule): RuleResource
    {
        return RuleResource::make($rule);
    }

    public function update(UpdateRuleRequest $request, Rule $rule, UpdateRule $updateRule): RuleResource
    {
        return RuleResource::make($updateRule->handle($rule, $request->validated()));
    }

    public function destroy(Rule $rule): Response
    {
        $rule->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRulesRequest $request, ReorderRules $reorderRules): Response
    {
        /** @var list<int> $ids */
        $ids = $request->validated()['ids'];
        $reorderRules->handle($ids);

        return response()->noContent();
    }
}
