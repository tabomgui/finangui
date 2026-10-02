<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Transfers\Actions\CreateTransfer;
use App\Domain\Transfers\Actions\DeleteTransfer;
use App\Domain\Transfers\Actions\UpdateTransfer;
use App\Domain\Transfers\Data\TransferData;
use App\Domain\Transfers\Support\TransferLegs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\StoreTransferRequest;
use App\Http\Requests\Transfers\UpdateTransferRequest;
use App\Http\Resources\TransferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class TransferController extends Controller
{
    public function store(StoreTransferRequest $request, CreateTransfer $createTransfer): JsonResponse
    {
        $legs = $createTransfer->handle(TransferData::fromArray($request->validated()));

        return TransferResource::make($legs)->response()->setStatusCode(201);
    }

    public function show(string $transfer): TransferResource
    {
        $legs = TransferLegs::load($transfer);

        return TransferResource::make(['out' => $legs['out']->load('account'), 'in' => $legs['in']->load('account')]);
    }

    public function update(UpdateTransferRequest $request, string $transfer, UpdateTransfer $updateTransfer): TransferResource
    {
        return TransferResource::make($updateTransfer->handle($transfer, $request->validated()));
    }

    public function destroy(string $transfer, DeleteTransfer $deleteTransfer): Response
    {
        $deleteTransfer->handle($transfer);

        return response()->noContent();
    }
}
