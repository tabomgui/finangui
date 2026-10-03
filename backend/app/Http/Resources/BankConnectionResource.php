<?php

namespace App\Http\Resources;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `accounts` espera a relação já carregada com o saldo calculado (ver
 * Account::scopeWithBalance()) — quem monta este resource carrega
 * `connection->load(['accounts' => fn ($q) => $q->withBalance()])` antes.
 *
 * @mixin BankConnection
 */
final class BankConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'institution_name' => $this->institution_name,
            'institution_logo_url' => $this->institution_logo_url,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'accounts' => $this->accounts->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'balance' => $account->balance()->cents,
                'provider_balance' => $account->provider_balance?->cents,
            ])->all(),
        ];
    }
}
