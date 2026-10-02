<?php

namespace App\Domain\Transactions\Data;

use App\Domain\Transactions\Enums\Direction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class TransactionData
{
    /**
     * @param  list<int>  $tagIds
     */
    public function __construct(
        public int $accountId,
        public CarbonImmutable $date,
        public Money $amount,
        public Direction $direction,
        public string $description,
        public ?int $categoryId = null,
        public ?string $notes = null,
        public ?string $payee = null,
        public bool $isIgnored = false,
        public array $tagIds = [],
        public ?int $statementId = null,
        public int $installments = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dados já validados
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: (int) $data['account_id'],
            date: CarbonImmutable::parse($data['date']),
            amount: Money::cents((int) $data['amount']),
            direction: Direction::from($data['direction']),
            description: $data['description'],
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            notes: $data['notes'] ?? null,
            payee: $data['payee'] ?? null,
            isIgnored: (bool) ($data['is_ignored'] ?? false),
            tagIds: array_map('intval', $data['tag_ids'] ?? []),
            statementId: isset($data['statement_id']) ? (int) $data['statement_id'] : null,
            installments: (int) ($data['installments'] ?? 1),
        );
    }
}
