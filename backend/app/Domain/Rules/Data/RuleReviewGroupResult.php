<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Enums\ReviewGroup;

/**
 * Um grupo de App\Domain\Rules\Data\RuleReviewResult: contagem completa e
 * até 500 itens, mais recentes primeiro (truncated quando há mais).
 */
final readonly class RuleReviewGroupResult
{
    /**
     * @param  list<CategoryChangeItem>  $items
     */
    public function __construct(
        public ReviewGroup $key,
        public int $count,
        public bool $truncated,
        public array $items,
    ) {}
}
