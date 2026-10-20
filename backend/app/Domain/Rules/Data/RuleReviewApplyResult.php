<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Actions\ApplyRuleReview::handle(): quantos
 * lançamentos mudaram e quantos dos ids enviados ficaram como estavam.
 */
final readonly class RuleReviewApplyResult
{
    public function __construct(
        public int $applied,
        public int $skipped,
    ) {}
}
