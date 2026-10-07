<?php

namespace App\Domain\Banking\Support;

/**
 * Resultado de App\Domain\Banking\Support\CardPaymentMatcher::match() para
 * uma transação reconhecida como pagamento (transações que não são pagamento
 * nenhum nunca aparecem na lista devolvida).
 */
final readonly class CardPaymentDecision
{
    public function __construct(
        public int $transactionId,
        public bool $isDuplicate,
        public ?string $billExternalId,
    ) {}
}
