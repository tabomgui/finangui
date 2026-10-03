<?php

namespace App\Http\Resources;

use App\Domain\Accounts\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'opening_balance' => $this->opening_balance->cents,
            'balance' => $this->resource->balance()->cents,
            'credit_limit' => $this->credit_limit?->cents,
            'closing_day' => $this->closing_day,
            'due_day' => $this->due_day,
            'last_four' => $this->last_four,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_archived' => $this->is_archived,
        ];
    }
}
