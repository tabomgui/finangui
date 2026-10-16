<?php

namespace App\Domain\Categorization\Data;

/**
 * Um grupo de App\Domain\Categorization\Data\PendingCategorizationResult:
 * pendentes com a mesma App\Domain\Categorization\Support\CounterpartyKey.
 * suggestionCategoryId só vem preenchido quando todos os lançamentos do
 * grupo são da mesma direção e o histórico sugere uma única categoria para
 * eles (ver App\Domain\Categorization\Queries\PendingCategorization).
 */
final readonly class PendingGroup
{
    /**
     * @param  list<PendingTransactionItem>  $transactions
     */
    public function __construct(
        public string $key,
        public string $name,
        public int $outCount,
        public int $inCount,
        public int $net,
        public ?int $suggestionCategoryId,
        public array $transactions,
    ) {}
}
