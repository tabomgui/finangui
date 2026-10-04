<?php

namespace App\Domain\Banking\Data;

use App\Domain\Transactions\Enums\Direction;

/**
 * Transação do provedor, já mapeada para o vocabulário do app. `fromArray()`
 * fica no provedor concreto (PluggyProvider), não aqui.
 */
final readonly class ProviderTransaction
{
    /**
     * @param  string  $date  "YYYY-MM-DD" (já em America/Sao_Paulo)
     * @param  int  $amountCents  sempre positivo
     * @param  array{number: int, total: int}|null  $installment
     */
    public function __construct(
        public string $id,
        public string $date,
        public int $amountCents,
        public Direction $direction,
        public string $description,
        public bool $pending,
        public ?string $categoryId,
        public ?array $installment,
        public ?string $purchaseDate,
        public ?string $billId,
    ) {}
}
