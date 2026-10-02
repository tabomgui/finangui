<?php

namespace App\Domain\Transfers\Data;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

final readonly class TransferData
{
    public function __construct(
        public int $fromAccountId,
        public int $toAccountId,
        public CarbonImmutable $date,
        public Money $amount,
        public string $description,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dados já validados
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fromAccountId: (int) $data['from_account_id'],
            toAccountId: (int) $data['to_account_id'],
            date: CarbonImmutable::parse($data['date']),
            amount: Money::cents((int) $data['amount']),
            description: $data['description'],
            notes: $data['notes'] ?? null,
        );
    }
}
