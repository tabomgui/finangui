<?php

namespace App\Http\Resources;

use App\Domain\Accounts\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o cartão vindo de CardOverview (saldo, uso do limite e fatura atual carregados).
 *
 * @mixin Account
 */
final class CardResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, currency: string, color: string|null, icon: string|null, is_archived: bool, last_four: string|null, credit_limit: int, closing_day: int, due_day: int, balance: int, limit: array{used: int, projected: int, available: int}, current_statement: CardStatementResource|null}
     */
    public function toArray(Request $request): array
    {
        $usage = $this->resource->cardUsage();
        $current = $this->resource->getRelation('currentStatement');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'currency' => $this->currency,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_archived' => $this->is_archived,
            'last_four' => $this->last_four,
            'credit_limit' => $this->credit_limit->cents ?? 0,
            'closing_day' => (int) $this->closing_day,
            'due_day' => (int) $this->due_day,
            'balance' => $this->resource->balance()->cents,
            'limit' => [
                'used' => $usage['used']->cents,
                'projected' => $usage['projected']->cents,
                'available' => $usage['available']->cents,
            ],
            'current_statement' => $current === null ? null : CardStatementResource::make($current),
        ];
    }
}
