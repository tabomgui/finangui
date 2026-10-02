<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cards\Actions\UpdateStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\UpdateStatementRequest;
use App\Http\Resources\CardStatementResource;

final class CardStatementController extends Controller
{
    public function show(CardStatement $statement): CardStatementResource
    {
        return CardStatementResource::make(CardStatement::query()->withTotals()->findOrFail($statement->id));
    }

    public function update(UpdateStatementRequest $request, CardStatement $statement, UpdateStatement $updateStatement): CardStatementResource
    {
        return CardStatementResource::make($updateStatement->handle($statement, $request->validated()));
    }
}
