<?php

namespace App\Domain\Banking\Data;

/**
 * Fatura do provedor (só Open Finance). `fromArray()` fica no provedor
 * concreto (PluggyProvider), não aqui.
 */
final readonly class ProviderBill
{
    /**
     * @param  string  $dueDate  "YYYY-MM-DD"
     * @param  ?string  $closingDate  "YYYY-MM-DD"
     * @param  list<ProviderBillPayment>  $payments  pagamentos que o banco já associa a esta fatura — ver App\Domain\Banking\Support\CardPaymentMatcher
     */
    public function __construct(
        public string $id,
        public string $dueDate,
        public ?string $closingDate,
        public int $totalCents,
        public array $payments = [],
    ) {}
}
