<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\CreateTransfer;
use App\Domain\Transfers\Actions\DeleteTransfer;
use App\Domain\Transfers\Actions\LinkTransfer;
use App\Domain\Transfers\Actions\UnlinkTransfer;
use App\Domain\Transfers\Actions\UpdateTransfer;
use App\Domain\Transfers\Data\TransferData;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Support\TransferLegs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\LinkTransferRequest;
use App\Http\Requests\Transfers\StoreTransferRequest;
use App\Http\Requests\Transfers\UpdateTransferRequest;
use App\Http\Resources\TransferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class TransferController extends Controller
{
    /**
     * "Juntar à mão" (seleção em massa de transações): os ids podem vir em
     * qualquer ordem — a direção real de cada transação, não a posição no
     * corpo do pedido, decide quem é a saída e quem é a entrada. Mesma
     * direção nos dois (ex.: duas saídas) é um par inválido, 409 como
     * qualquer outra violação de LinkTransfer::assertLinkable(). Janela de
     * 7 dias (maior que a da detecção automática): decisão humana, não um
     * palpite por pontuação.
     */
    public function link(LinkTransferRequest $request, LinkTransfer $linkTransfer): JsonResponse
    {
        $a = Transaction::query()->findOrFail($request->validated('out_transaction_id'));
        $b = Transaction::query()->findOrFail($request->validated('in_transaction_id'));

        if ($a->direction === $b->direction) {
            throw new TransferLinkInvalid;
        }

        [$out, $in] = $a->direction === Direction::Out ? [$a, $b] : [$b, $a];

        $linkTransfer->handle($out, $in, maxDays: 7);

        $legs = ['out' => $out->refresh()->load('account'), 'in' => $in->refresh()->load('account')];

        return TransferResource::make($legs)->response()->setStatusCode(201);
    }

    public function unlink(string $transfer, UnlinkTransfer $unlinkTransfer): Response
    {
        $unlinkTransfer->handle($transfer);

        return response()->noContent();
    }

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
