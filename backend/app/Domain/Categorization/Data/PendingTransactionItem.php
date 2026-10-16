<?php

namespace App\Domain\Categorization\Data;

use App\Domain\Transactions\Enums\Direction;

/**
 * Um lançamento de App\Domain\Categorization\Data\PendingGroup::$transactions.
 */
final readonly class PendingTransactionItem
{
    public function __construct(
        public int $id,
        public string $date,
        public string $description,
        public string $originalDescription,
        public int $amount,
        public Direction $direction,
        public int $accountId,
        public string $accountName,
    ) {}
}
