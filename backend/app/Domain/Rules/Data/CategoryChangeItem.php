<?php

namespace App\Domain\Rules\Data;

use App\Domain\Transactions\Enums\Direction;

/**
 * Uma linha exibida da reconciliação de regra. Nulos (sem
 * categoria atual, sem categoria nova, sem regra) saem omitidos da resposta.
 */
final readonly class CategoryChangeItem
{
    public function __construct(
        public int $transactionId,
        public string $date,
        public string $description,
        public string $accountName,
        public int $amount,
        public Direction $direction,
        public ?int $currentCategoryId,
        public ?int $newCategoryId,
        public ?int $ruleId,
        public ?string $ruleName,
    ) {}
}
