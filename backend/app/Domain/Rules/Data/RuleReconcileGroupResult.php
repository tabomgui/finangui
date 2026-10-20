<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Enums\ReconcileClearReason;
use App\Domain\Rules\Enums\ReconcileGroup;

/**
 * Um grupo de App\Domain\Rules\Data\RuleReconcileResult: contagem completa
 * e amostra de até 20, mais recentes primeiro. reason só no grupo "clear".
 */
final readonly class RuleReconcileGroupResult
{
    /**
     * @param  list<CategoryChangeItem>  $sample
     */
    public function __construct(
        public ReconcileGroup $key,
        public int $count,
        public ?ReconcileClearReason $reason,
        public array $sample,
    ) {}
}
