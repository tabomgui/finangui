<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Transactions\Actions\CreateTransaction;
use App\Domain\Transactions\Actions\DeleteTransaction;
use App\Domain\Transactions\Actions\UpdateTransaction;
use App\Domain\Transactions\Data\TransactionData;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transactions\Queries\TransactionQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\IndexTransactionsRequest;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TransactionController extends Controller
{
    public function index(IndexTransactionsRequest $request): AnonymousResourceCollection
    {
        $transactions = TransactionQuery::filtered($request->validated())
            ->cursorPaginate($request->integer('per_page', 50))
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function store(StoreTransactionRequest $request, CreateTransaction $createTransaction): JsonResponse
    {
        return TransactionResource::make($createTransaction->handle(TransactionData::fromArray($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return TransactionResource::make($transaction->load(['account', 'category.parent', 'tags']));
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, UpdateTransaction $updateTransaction): TransactionResource
    {
        return TransactionResource::make($updateTransaction->handle($transaction, $request->validated()));
    }

    public function destroy(Transaction $transaction, DeleteTransaction $deleteTransaction): Response
    {
        $deleteTransaction->handle($transaction);

        return response()->noContent();
    }
}
