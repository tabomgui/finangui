<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Queries\ReviewRules::handle(): os quatro
 * grupos, sempre presentes e nesta ordem (fill, conflict, stale, manual), e
 * o máximo de lançamentos que uma aplicação aceita.
 */
final readonly class RuleReviewResult
{
    /**
     * @param  list<RuleReviewGroupResult>  $groups
     */
    public function __construct(
        public array $groups,
        public int $maxChanges,
    ) {}
}
