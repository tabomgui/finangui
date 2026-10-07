<?php

namespace App\Http\Resources;

use App\Domain\Banking\Models\BankSyncRunItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um lançamento adicionado por uma sincronização (detalhe de
 * `GET /bank-sync-runs/{id}`) — snapshot, não a transação em si (que pode já
 * ter sido excluída, ver App\Domain\Banking\Models\BankSyncRunItem).
 * `transaction_id` fica omitido (nunca `null`) quando a transação original
 * foi excluída.
 *
 * @mixin BankSyncRunItem
 */
final class BankSyncRunItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->when($this->transaction_id !== null, fn () => $this->transaction_id),
            'account_name' => $this->account_name,
            'date' => $this->date->toDateString(),
            'description' => $this->description,
            'amount' => $this->amount->cents,
            'direction' => $this->direction,
        ];
    }
}
