<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Recurrences\Actions\ConfirmOccurrence;
use App\Domain\Recurrences\Actions\CreateRecurrence;
use App\Domain\Recurrences\Actions\DeleteRecurrence;
use App\Domain\Recurrences\Actions\SkipOccurrence;
use App\Domain\Recurrences\Actions\UpdateRecurrence;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Recurrences\Queries\OverdueOccurrences;
use App\Domain\Recurrences\Queries\RecurrenceList;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recurrences\ConfirmOccurrenceRequest;
use App\Http\Requests\Recurrences\StoreRecurrenceRequest;
use App\Http\Requests\Recurrences\UpdateRecurrenceRequest;
use App\Http\Resources\RecurrenceResource;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class RecurrenceController extends Controller
{
    public function index(RecurrenceList $list): AnonymousResourceCollection
    {
        return RecurrenceResource::collection($list->all());
    }

    public function store(StoreRecurrenceRequest $request, CreateRecurrence $createRecurrence): JsonResponse
    {
        return RecurrenceResource::make($createRecurrence->handle($request->validated()))
            ->response()->setStatusCode(201);
    }

    public function show(Recurrence $recurrence): RecurrenceResource
    {
        return RecurrenceResource::make($recurrence->load(['account', 'category']));
    }

    public function update(UpdateRecurrenceRequest $request, Recurrence $recurrence, UpdateRecurrence $updateRecurrence): RecurrenceResource
    {
        return RecurrenceResource::make($updateRecurrence->handle($recurrence, $request->validated()));
    }

    public function destroy(Recurrence $recurrence, DeleteRecurrence $deleteRecurrence): Response
    {
        $deleteRecurrence->handle($recurrence);

        return response()->noContent();
    }

    public function overdue(OverdueOccurrences $overdueOccurrences): AnonymousResourceCollection
    {
        return TransactionResource::collection($overdueOccurrences->handle());
    }

    public function confirm(ConfirmOccurrenceRequest $request, Transaction $transaction, ConfirmOccurrence $confirmOccurrence): TransactionResource
    {
        return TransactionResource::make($confirmOccurrence->handle($transaction, $request->validated()));
    }

    public function skip(Transaction $transaction, SkipOccurrence $skipOccurrence): Response
    {
        $skipOccurrence->handle($transaction);

        return response()->noContent();
    }
}
