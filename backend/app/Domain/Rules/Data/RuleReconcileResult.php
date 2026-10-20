<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Queries\ReconcileRule::handle(): os três
 * grupos, sempre presentes e nesta ordem (recategorize, fill, clear), e o
 * máximo de alterações que uma aplicação aceita.
 */
final readonly class RuleReconcileResult
{
    /**
     * @param  list<RuleReconcileGroupResult>  $groups
     */
    public function __construct(
        public array $groups,
        public int $maxChanges,
    ) {}
}
