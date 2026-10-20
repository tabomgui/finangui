<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Actions\ApplyRuleReconcile::handle(): quantos
 * lançamentos mudaram em cada grupo (0 nos grupos não escolhidos) e quantos
 * saíram do grupo entre a contagem e a trava, sem mudar.
 */
final readonly class RuleReconcileApplyResult
{
    public function __construct(
        public int $recategorize,
        public int $fill,
        public int $clear,
        public int $skipped,
    ) {}
}
