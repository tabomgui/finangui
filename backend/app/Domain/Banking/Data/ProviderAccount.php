<?php

namespace App\Domain\Banking\Data;

/**
 * Conta do provedor, já mapeada para o vocabulário do app. `fromArray()`
 * fica no provedor concreto (PluggyProvider), não aqui.
 */
final readonly class ProviderAccount
{
    /**
     * @param  'checking'|'savings'|'credit_card'  $kind
     * @param  int  $balanceCents  saldo em centavos; em cartão, valor devido positivo
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public ?string $number,
        public string $currency,
        public int $balanceCents,
        public ?int $creditLimitCents = null,
        public ?int $closingDay = null,
        public ?int $dueDay = null,
    ) {}
}
