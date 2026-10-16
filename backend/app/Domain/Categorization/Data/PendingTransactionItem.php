<?php

namespace App\Domain\Categorization\Data;

/**
 * Um lançamento de App\Domain\Categorization\Data\PendingGroup::$transactions.
 */
final readonly class PendingTransactionItem
{
    public function __construct(
        public int $id,
        public string $date,
        public string $description,
        public int $amount,
        public string $direction,
        public int $accountId,
        public string $accountName,
    ) {}
}
