<?php

namespace App\Http\Resources;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array{
     *     id: int, name: string, type: AccountType, currency: string,
     *     opening_balance: int, balance: int, credit_limit: int|null,
     *     closing_day: int|null, due_day: int|null, last_four: string|null,
     *     color: string|null, icon: string|null, is_archived: bool,
     *     connection_id: int|null, provider_balance: int|null,
     *     provider_synced_at: string|null, ledger_balance?: int,
     * }
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
            'connection_id' => $this->connection_id,
            'provider_balance' => $this->provider_balance?->cents,
            'provider_synced_at' => $this->provider_synced_at?->toIso8601String(),
        ] + $this->ledgerBalance();
    }

    /**
     * `ledger_balance` (opening_balance + lançamentos pelo razão interno,
     * sempre "hoje" — ver Account::ledgerBalance()) só existe para conta
     * conectada a um banco: é o lado "nosso" da comparação com
     * provider_balance, usada pelo aviso de divergência no frontend
     * (connection-card). Omitido — nunca null — fora desse caso.
     *
     * @return array{ledger_balance: int}|array{}
     */
    private function ledgerBalance(): array
    {
        return $this->connection_id !== null ? ['ledger_balance' => $this->resource->ledgerBalance()->cents] : [];
    }
}
