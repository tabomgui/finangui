<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Enums\RuleField;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Models\Transaction;
use LogicException;

/**
 * Campos de uma transação que as condições enxergam, já no formato de
 * comparação: textos normalizados, valor em centavos, data ISO.
 */
final readonly class RuleSubject
{
    public function __construct(
        public string $description,
        public string $originalDescription,
        public string $payee,
        public string $notes,
        public int $amount,
        public string $direction,
        public int $accountId,
        public string $date,
    ) {}

    public static function fromTransaction(Transaction $transaction): self
    {
        return new self(
            description: TextNormalizer::normalize($transaction->description),
            originalDescription: TextNormalizer::normalize($transaction->original_description),
            payee: TextNormalizer::normalize($transaction->payee),
            notes: TextNormalizer::normalize($transaction->notes),
            amount: $transaction->amount->cents,
            direction: $transaction->direction->value,
            accountId: $transaction->account_id,
            date: $transaction->date->toDateString(),
        );
    }

    public function text(RuleField $field): string
    {
        return match ($field) {
            RuleField::Description => $this->description,
            RuleField::OriginalDescription => $this->originalDescription,
            RuleField::Payee => $this->payee,
            RuleField::Notes => $this->notes,
            default => throw new LogicException("{$field->value} não é campo de texto."),
        };
    }
}
