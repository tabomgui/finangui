<?php

namespace App\Http\Resources;

use App\Domain\Transactions\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
final class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'color' => $this->account->color,
                'icon' => $this->account->icon,
            ]),
            'date' => $this->date->toDateString(),
            'amount' => $this->amount->cents,
            'direction' => $this->direction,
            'currency' => $this->currency,
            'description' => $this->description,
            'original_description' => $this->original_description,
            'description_locked' => $this->description_locked,
            'notes' => $this->notes,
            'payee' => $this->payee,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'parent_id' => $this->category->parent_id,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                'color' => $this->category->color,
                'is_transfer' => $this->category->is_transfer,
                // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que parent_id/parent são nullable; em runtime uma categoria raiz não tem parent)
                'is_transfer_effective' => (bool) ($this->category->is_transfer || ($this->category->parent?->is_transfer ?? false)),
            ] : null),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'status' => $this->status,
            'source' => $this->source,
            'categorized_by' => $this->categorized_by,
            'is_ignored' => $this->is_ignored,
            'transfer_id' => $this->transfer_id,
            'statement_id' => $this->statement_id,
            'installment' => $this->whenLoaded('installmentPlan', fn () => $this->installmentPlan ? [
                'plan_id' => $this->installmentPlan->id,
                'number' => (int) $this->installment_number,
                'total' => $this->installmentPlan->installments,
            ] : null),
        ];
    }
}
