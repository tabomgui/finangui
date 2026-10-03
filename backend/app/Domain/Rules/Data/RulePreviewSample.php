<?php

namespace App\Domain\Rules\Data;

use App\Domain\Transactions\Models\Transaction;

/**
 * Uma transação da amostra da prévia e o que mudaria nela. As 5 propriedades
 * de mudança saem sempre presentes (nulo/lista vazia/false quando aquela
 * mudança não se aplica), para o tipo no frontend ficar estável.
 */
final readonly class RulePreviewSample
{
    /**
     * @param  list<int>  $tagIds
     */
    public function __construct(
        public Transaction $transaction,
        public ?int $categoryId,
        public ?string $description,
        public ?string $payee,
        public array $tagIds,
        public bool $isIgnored,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  saída de ApplyRuleOutcome::changes()
     */
    public static function fromChanges(Transaction $transaction, array $changes): self
    {
        return new self(
            transaction: $transaction,
            categoryId: $changes['category_id'] ?? null,
            description: $changes['description'] ?? null,
            payee: $changes['payee'] ?? null,
            tagIds: $changes['tag_ids'] ?? [],
            isIgnored: $changes['is_ignored'] ?? false,
        );
    }
}
