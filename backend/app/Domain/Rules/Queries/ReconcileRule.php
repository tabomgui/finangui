<?php

namespace App\Domain\Rules\Queries;

use App\Domain\Rules\Data\CategoryChange;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleReconcileGroupResult;
use App\Domain\Rules\Data\RuleReconcileResult;
use App\Domain\Rules\Enums\ReconcileClearReason;
use App\Domain\Rules\Enums\ReconcileGroup;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Transactions\Models\Transaction;

/**
 * Prévia, depois de editar uma regra, do que mudaria só na categoria dos
 * lançamentos (ver CategoryChangePlanner::forRule()): contagem e amostra de
 * até 20 por grupo. Regra desativada só tira a categoria do que ela
 * categorizou (motivo "inactive"). Nunca grava nada; a aplicação
 * (App\Domain\Rules\Actions\ApplyRuleReconcile) recalcula tudo.
 */
final class ReconcileRule
{
    private const SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly CategoryChangePlanner $planner,
    ) {}

    public function handle(Rule $rule): RuleReconcileResult
    {
        $definition = RuleDefinition::fromRule($rule);

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, list<CategoryChange>> $samples */
        $samples = [];

        foreach (ReconcileGroup::cases() as $group) {
            $counts[$group->value] = 0;
            $samples[$group->value] = [];
        }

        $this->planner->reconcileCandidates($rule->id)
            ->lazyByIdDesc(500)
            ->each(function (Transaction $transaction) use ($definition, $rule, &$counts, &$samples): void {
                $change = $this->planner->forRule($transaction, $definition, $rule->is_active);

                if ($change === null) {
                    return;
                }

                $key = $change->group->value;
                $counts[$key]++;

                if (count($samples[$key]) < self::SAMPLE_LIMIT) {
                    $samples[$key][] = $change;
                }
            });

        $reason = match (true) {
            ! $rule->is_active => ReconcileClearReason::Inactive,
            $definition->setsCategory() => ReconcileClearReason::NoLongerMatches,
            default => ReconcileClearReason::NoCategoryAction,
        };

        $groups = array_map(fn (ReconcileGroup $group) => new RuleReconcileGroupResult(
            key: $group,
            count: $counts[$group->value],
            reason: $group === ReconcileGroup::Clear ? $reason : null,
            sample: $this->planner->items($samples[$group->value]),
        ), ReconcileGroup::cases());

        return new RuleReconcileResult($groups, CategoryChangePlanner::MAX_CHANGES);
    }
}
