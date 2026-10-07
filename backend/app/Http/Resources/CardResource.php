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
     * @return array{id: int, name: string, currency: string, color: string|null, icon: string|null, is_archived: bool, last_four: string|null, credit_limit: int, closing_day: int, due_day: int, balance: int, limit: array{used: int, projected: int, available: int}, used_limit: int, available_limit?: int, current_statement: CardStatementResource|null}
     */
    public function toArray(Request $request): array
    {
        $usage = $this->resource->cardUsage();
        $current = $this->resource->getRelation('currentStatement');

        // Dados do banco (conta de cartão sincronizada): provider_balance já
        // é o limite usado do cartão (negativo — ver App\Domain\Banking\Support\AccountMapper::appBalanceCents()).
        // Sem isso, usa o calculado hoje (withCardUsage) e o limite cadastrado.
        $hasBankData = $this->resource->connection_id !== null && $this->resource->provider_balance !== null;
        $registeredCreditLimitCents = $this->resource->credit_limit?->cents;

        // max(0, ...): um saldo credor do cartão (provider_balance positivo,
        // ou mais pago do que usado no cálculo local) nunca é "limite usado"
        // negativo. Cast (int) só para o Scramble conseguir inferir o tipo do
        // array (max() devolve int|float para ele).
        $usedLimitCents = (int) max(0, $hasBankData ? $this->resource->provider_balance->negated()->cents : $usage['used']->cents);

        $availableLimitCents = match (true) {
            $hasBankData && $this->resource->available_credit_limit !== null => $this->resource->available_credit_limit->cents,
            // Sem o disponível do banco: deriva do limite (do banco ou
            // cadastrado) menos o usado já calculado acima — nunca o
            // disponível de App\Domain\Accounts\Models\Account::cardUsage(),
            // que soma também o projetado (parcelas futuras) e pode não
            // bater com o usado exibido aqui quando ele vem do banco.
            $registeredCreditLimitCents !== null => $registeredCreditLimitCents - $usedLimitCents,
            default => null,
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'currency' => $this->currency,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_archived' => $this->is_archived,
            'last_four' => $this->last_four,
            'credit_limit' => $registeredCreditLimitCents ?? 0,
            'closing_day' => (int) $this->closing_day,
            'due_day' => (int) $this->due_day,
            'balance' => $this->resource->balance()->cents,
            'limit' => [
                'used' => $usage['used']->cents,
                'projected' => $usage['projected']->cents,
                'available' => $usage['available']->cents,
            ],
            'used_limit' => $usedLimitCents,
            'available_limit' => $this->when($availableLimitCents !== null, fn (): int => $availableLimitCents),
            'current_statement' => $current === null ? null : CardStatementResource::make($current),
        ];
    }
}
