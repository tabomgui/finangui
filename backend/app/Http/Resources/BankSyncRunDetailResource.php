<?php

namespace App\Http\Resources;

use App\Domain\Banking\Models\BankSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalhe de uma run (`GET /bank-sync-runs/{id}`): os mesmos campos de
 * App\Http\Resources\BankSyncRunResource (reaproveitado aqui, não repetido),
 * mais `items` (os lançamentos adicionados, com snapshot — ver
 * App\Domain\Banking\Models\BankSyncRunItem, sempre ordenados por data e
 * depois id — ver BankSyncRunController::show()) e `items_truncated`, true
 * quando `added_count` passou do limite de itens gravados com snapshot (ver
 * App\Domain\Banking\Jobs\SyncConnection::MAX_RUN_ITEMS) — a lista não
 * mostra todos, só a contagem já cobre o resto. `items` precisa vir
 * carregada (`$run->load('items')`) por quem monta este resource.
 *
 * @mixin BankSyncRun
 */
final class BankSyncRunDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new BankSyncRunResource($this->resource))->toArray($request),
            'items' => BankSyncRunItemResource::collection($this->items),
            'items_truncated' => $this->added_count > $this->items->count(),
        ];
    }
}
