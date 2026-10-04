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
     */
    public function __construct(
        public string $id,
        public string $dueDate,
        public ?string $closingDate,
        public int $totalCents,
    ) {}
}
