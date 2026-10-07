<?php

namespace App\Http\Resources;

use App\Domain\Banking\Models\BankSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha do histórico de sincronização (`GET /bank-connections/{id}/sync-runs`).
 * `finished_at`/`provider_updated_at`/`error`/`stats` ficam omitidos (nunca
 * `null`) enquanto a run não tem valor para eles — ver o comentário em
 * App\Http\Resources\TransactionResource sobre por que uma chave tipada só
 * como `null` desaparece do cliente gerado. `warnings` é sempre lista: vazia
 * quando a run ainda está `running` (nenhum aviso ainda) ou terminou sem
 * nenhum. `stats` são os contadores de reconciliação de pagamentos (ver
 * App\Domain\Banking\Jobs\SyncConnection::reconcileStatsPayload()) que não
 * geraram aviso.
 *
 * App\Http\Resources\BankSyncRunDetailResource (`GET /bank-sync-runs/{id}`)
 * reaproveita este `toArray()` (em vez de repetir os mesmos campos) e só
 * acrescenta `items`/`items_truncated`.
 *
 * @mixin BankSyncRun
 */
final class BankSyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->connection_id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->when($this->finished_at !== null, fn () => $this->finished_at->toIso8601String()),
            'refresh_requested' => $this->refresh_requested,
            'provider_updated_at' => $this->when($this->provider_updated_at !== null, fn () => $this->provider_updated_at->toIso8601String()),
            'added_count' => $this->added_count,
            'updated_count' => $this->updated_count,
            'bills_count' => $this->bills_count,
            'warnings' => $this->warnings ?? [],
            'stats' => $this->when($this->stats !== null, fn () => $this->stats),
            'error' => $this->when($this->error !== null, fn () => $this->error),
        ];
    }
}
