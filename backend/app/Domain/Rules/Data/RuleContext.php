<?php

namespace App\Domain\Rules\Data;

use App\Domain\Transactions\Models\Transaction;

/**
 * Estado da transação que limita o que as regras podem mudar: se já tem
 * categoria, se foi definida à mão, se a descrição está travada, se a
 * aplicação retroativa pode sobrescrever e se o lançamento é manual (só
 * recebe categoria, nunca as outras ações).
 */
final readonly class RuleContext
{
    public function __construct(
        public bool $hasCategory,
        public bool $categoryManual,
        public bool $descriptionLocked,
        public bool $overwrite,
        public bool $onlyCategory,
    ) {}

    /**
     * Contexto de uma transação já existente (prévia e aplicação
     * retroativa): todas as ações valem, não só categoria.
     */
    public static function forExisting(Transaction $transaction, bool $overwrite): self
    {
        return new self(
            hasCategory: $transaction->category_id !== null,
            categoryManual: $transaction->categorized_by === 'manual',
            descriptionLocked: $transaction->description_locked,
            overwrite: $overwrite,
            onlyCategory: false,
        );
    }
}
