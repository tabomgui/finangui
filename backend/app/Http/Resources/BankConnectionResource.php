<?php

namespace App\Http\Resources;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\PendingProviderAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `accounts` espera a relação já carregada com o saldo calculado (ver
 * Account::scopeWithBalance()) — quem monta este resource carrega
 * `connection->load(['accounts' => fn ($q) => $q->withBalance()])` antes.
 *
 * `pending_accounts` é sempre uma lista (nunca omitida nem null): vazia
 * fora de `pending_link` (settings.pending_accounts não existe mais depois
 * do vínculo — PendingProviderAccounts::suggestionsFor() já devolve lista
 * vazia nesse caso, sem precisar checar o status aqui), com as contas do
 * banco (e a sugestão de vínculo recalculada na hora) enquanto a conexão
 * ainda espera o vínculo — permite a tela retomar o fluxo sem recriar a
 * conexão.
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
                'currency' => $account->currency,
                'color' => $account->color,
                'icon' => $account->icon,
                'is_archived' => $account->is_archived,
                'balance' => $account->balance()->cents,
                'provider_balance' => $account->provider_balance?->cents,
            ])->all(),
            'pending_accounts' => ProviderAccountResource::collection(PendingProviderAccounts::suggestionsFor($this->resource)),
        ];
    }
}
