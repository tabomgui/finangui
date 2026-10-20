<?php

namespace App\Domain\Rules\Queries;

use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleReconcileGroupResult;
use App\Domain\Rules\Data\RuleReconcileResult;
use App\Domain\Rules\Enums\ReconcileClearReason;
use App\Domain\Rules\Enums\ReconcileGroup;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Rules\Support\MostRecentChanges;
use App\Domain\Transactions\Models\Transaction;

/**
 * Prévia, depois de editar uma regra, do que mudaria só na categoria dos
 * lançamentos (ver CategoryChangePlanner::forRule()): a regra editada é
 * avaliada junto com as regras ativas, primeira que define categoria vence,
 * como na revisão de regras. Contagem e amostra dos 20 mais recentes (pela
 * data do lançamento) por grupo. Regra desativada só tira a categoria do
 * que ela categorizou e nenhuma regra ativa categoriza (motivo "inactive").
 * Nunca grava nada; a aplicação (App\Domain\Rules\Actions\ApplyRuleReconcile)
 * recalcula tudo.
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
        $rules = $this->planner->activeRules();

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, MostRecentChanges> $samples */
        $samples = [];

        foreach (ReconcileGroup::cases() as $group) {
            $counts[$group->value] = 0;
            $samples[$group->value] = new MostRecentChanges(self::SAMPLE_LIMIT);
        }

        $this->planner->reconcileCandidates($rule->id)
            ->lazyById(500)
            ->each(function (Transaction $transaction) use ($rule, $rules, &$counts, &$samples): void {
                $change = $this->planner->forRule($transaction, $rule->id, $rules);

                if ($change === null) {
                    return;
                }

                $key = $change->group->value;
                $counts[$key]++;
                $samples[$key]->add($change);
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
            sample: $this->planner->items($samples[$group->value]->all()),
        ), ReconcileGroup::cases());

        return new RuleReconcileResult($groups, CategoryChangePlanner::MAX_CHANGES);
    }
}
