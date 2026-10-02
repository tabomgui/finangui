<?php

namespace App\Http\Resources;

use App\Domain\Transactions\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recebe as duas pernas: ['out' => Transaction, 'in' => Transaction].
 *
 * @property array{out: Transaction, in: Transaction} $resource
 */
final class TransferResource extends JsonResource
{
    /**
     * @return array{transfer_id: string, date: string, amount: int, description: string, notes: string|null, from: TransactionResource, to: TransactionResource}
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $out */
        $out = $this->resource['out'];
        /** @var Transaction $in */
        $in = $this->resource['in'];

        return [
            'transfer_id' => $out->transfer_id,
            'date' => $out->date->toDateString(),
            'amount' => (int) $out->amount->cents,
            'description' => $out->description,
            'notes' => $out->notes === null ? null : (string) $out->notes,
            'from' => TransactionResource::make($out),
            'to' => TransactionResource::make($in),
        ];
    }
}
