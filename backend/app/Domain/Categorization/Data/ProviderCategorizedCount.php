<?php

namespace App\Domain\Categorization\Data;

/**
 * Quantos lançamentos do usuário estão com a categoria que veio do banco
 * (Transaction::scopeCategorizedByProvider()) — o mesmo conjunto que
 * App\Domain\Categorization\Actions\UpdateProviderCategoriesSetting limpa.
 */
final readonly class ProviderCategorizedCount
{
    public function __construct(
        public int $count,
    ) {}
}
