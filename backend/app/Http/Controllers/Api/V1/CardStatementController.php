<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cards\Actions\PayStatement;
use App\Domain\Cards\Actions\UpdateStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\PayStatementRequest;
use App\Http\Requests\Cards\UpdateStatementRequest;
use App\Http\Resources\CardStatementResource;
use App\Http\Resources\TransferResource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

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

    public function pay(PayStatementRequest $request, CardStatement $statement, PayStatement $payStatement): JsonResponse
    {
        $data = $request->validated();
        $legs = $payStatement->handle(
            $statement,
            (int) $data['from_account_id'],
            Money::cents((int) $data['amount']),
            CarbonImmutable::parse($data['date']),
            $data['description'] ?? null,
        );

        return TransferResource::make($legs)->response()->setStatusCode(201);
    }
}
