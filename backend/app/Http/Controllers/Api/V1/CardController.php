<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Queries\CardOverview;
use App\Domain\Cards\Support\StatementResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\IndexCardsRequest;
use App\Http\Requests\Cards\StatementPreviewRequest;
use App\Http\Resources\CardResource;
use App\Http\Resources\CardStatementResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CardController extends Controller
{
    public function index(IndexCardsRequest $request, CardOverview $overview): AnonymousResourceCollection
    {
        return CardResource::collection($overview->list($request->boolean('include_archived')));
    }

    public function show(Account $account, CardOverview $overview): CardResource
    {
        return CardResource::make($overview->find($account->id));
    }

    public function statements(Account $account): AnonymousResourceCollection
    {
        $this->ensureCard($account);

        return CardStatementResource::collection(
            CardStatement::query()->withTotals()->where('account_id', $account->id)->orderBy('due_date')->get(),
        );
    }

    public function statementPreview(StatementPreviewRequest $request, Account $account, StatementResolver $resolver): JsonResponse
    {
        $this->ensureCard($account);
        $statement = $resolver->locate($account, CarbonImmutable::parse($request->validated('date')));

        /** @var array{data: array{statement_id: int|null, closing_date: string, due_date: string}} $payload */
        $payload = ['data' => [
            'statement_id' => $statement->exists ? $statement->id : null,
            'closing_date' => $statement->closing_date->toDateString(),
            'due_date' => $statement->due_date->toDateString(),
        ]];

        return response()->json($payload);
    }

    private function ensureCard(Account $account): void
    {
        abort_unless($account->isCreditCard(), 404);
    }
}
