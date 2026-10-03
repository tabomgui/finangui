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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TransferSuggestionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $suggestions = TransferSuggestion::query()
            ->where('status', TransferSuggestionStatus::Pending)
            ->with(['outTransaction.account', 'inTransaction.account'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return TransferSuggestionResource::collection($suggestions);
    }

    /**
     * Sob demanda (botão "Procurar transferências"), além da detecção
     * automática ao fim de cada importação/sync: últimos 90 dias (ver
     * App\Domain\Transfers\Actions\DetectTransfers).
     */
    public function detect(DetectTransfers $detectTransfers): JsonResponse
    {
        return response()->json(['data' => $detectTransfers->handle()]);
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
