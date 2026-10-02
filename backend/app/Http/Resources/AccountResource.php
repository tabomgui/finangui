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
            'balance' => $this->when($this->resource->hasBalance(), fn () => $this->resource->balance()->cents),
            'color' => $this->color,
            'icon' => $this->icon,
            'is_archived' => $this->is_archived,
        ];
    }
}
