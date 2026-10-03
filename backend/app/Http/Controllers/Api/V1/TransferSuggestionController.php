<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Transfers\Actions\AcceptTransferSuggestion;
use App\Domain\Transfers\Actions\DetectTransfers;
use App\Domain\Transfers\Actions\DismissTransferSuggestion;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransferResource;
use App\Http\Resources\TransferSuggestionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TransferSuggestionController extends Controller
{
    /**
     * Paginada por cursor, como a listagem de transações
     * (TransactionController::index()): pode crescer bastante numa conta
     * movimentada, então nunca devolve tudo de uma vez.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $suggestions = TransferSuggestion::query()
            ->where('status', TransferSuggestionStatus::Pending)
            ->with(['outTransaction.account', 'inTransaction.account'])
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->withQueryString();

        return TransferSuggestionResource::collection($suggestions);
    }

    /**
     * Sob demanda (botão "Procurar transferências"), além da detecção
     * automática ao fim de cada importação/sync: últimos 90 dias (ver
     * App\Domain\Transfers\Actions\DetectTransfers). `undo` não faz parte
     * da resposta: só interessa a IngestTransactions, que mescla isso ao
     * undo do próprio lote — aqui não existe lote nenhum. Os casts (int)
     * são de propósito: sem eles, o Scramble perde o tipo exato ao ler de
     * volta uma chave de um array já desestruturado e documenta `linked`/
     * `suggested` como string.
     */
    public function detect(DetectTransfers $detectTransfers): JsonResponse
    {
        $result = $detectTransfers->handle();

        return response()->json(['data' => ['linked' => (int) $result['linked'], 'suggested' => (int) $result['suggested']]]);
    }

    public function accept(TransferSuggestion $suggestion, AcceptTransferSuggestion $accept): TransferResource
    {
        return TransferResource::make($accept->handle($suggestion));
    }

    public function dismiss(TransferSuggestion $suggestion, DismissTransferSuggestion $dismiss): Response
    {
        $dismiss->handle($suggestion);

        return response()->noContent();
    }
}
