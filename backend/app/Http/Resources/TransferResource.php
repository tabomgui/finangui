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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $out = $this->resource['out'];
        $in = $this->resource['in'];

        return [
            'transfer_id' => $out->transfer_id,
            'date' => $out->date->toDateString(),
            'amount' => $out->amount->cents,
            'description' => $out->description,
            'notes' => $out->notes,
            'from' => TransactionResource::make($out),
            'to' => TransactionResource::make($in),
        ];
    }
}
