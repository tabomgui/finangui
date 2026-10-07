<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankSyncRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\IndexBankSyncRunsRequest;
use App\Http\Resources\BankSyncRunDetailResource;
use App\Http\Resources\BankSyncRunResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Histórico de sincronização (App\Domain\Banking\Models\BankSyncRun) de uma
 * conexão — fora de App\Http\Middleware\EnsureBankingEnabled, como
 * BankConnectionController::index()/destroy(): ver um histórico já gravado
 * não depende de credenciais continuarem válidas agora. Isolamento por
 * usuário vem do global scope de BelongsToUser (nos dois models) somado ao
 * route model binding, que já resolve 404 para uma conexão/run de outro
 * usuário.
 */
final class BankSyncRunController extends Controller
{
    public function index(IndexBankSyncRunsRequest $request, BankConnection $connection): AnonymousResourceCollection
    {
        $runs = BankSyncRun::query()
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 20))
            ->withQueryString();

        return BankSyncRunResource::collection($runs);
    }

    public function show(BankSyncRun $run): BankSyncRunDetailResource
    {
        return BankSyncRunDetailResource::make(
            $run->load(['items' => fn ($query) => $query->orderBy('date')->orderBy('id')]),
        );
    }
}
