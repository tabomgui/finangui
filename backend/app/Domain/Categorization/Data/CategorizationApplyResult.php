<?php

namespace App\Domain\Categorization\Data;

/**
 * Resultado de App\Domain\Categorization\Actions\ApplyCategorization::handle().
 * ruleApplied/ruleId/ruleName ficam nulos quando a chamada não passou
 * "rule" (ruleApplied também fica nulo com "rule" sem apply_to_pending).
 */
final readonly class CategorizationApplyResult
{
    public function __construct(
        public int $categorized,
        public int $skipped,
        public ?int $ruleApplied,
        public ?int $ruleId,
        public ?string $ruleName,
    ) {}
}
