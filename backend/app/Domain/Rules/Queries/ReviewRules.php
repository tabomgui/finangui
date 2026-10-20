<?php

namespace App\Domain\Rules\Queries;

use App\Domain\Rules\Data\CategoryChange;
use App\Domain\Rules\Data\RuleReviewGroupResult;
use App\Domain\Rules\Data\RuleReviewResult;
use App\Domain\Rules\Enums\ReviewGroup;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Transactions\Models\Transaction;

/**
 * Revisão de todas as regras ativas contra os lançamentos já existentes,
 * só a categoria (ver CategoryChangePlanner::forRules()): regras carregadas
 * uma vez, lançamentos lidos em lotes. Contagem completa e até 500 itens por
 * grupo, mais recentes primeiro. Nunca grava nada; a aplicação
 * (App\Domain\Rules\Actions\ApplyRuleReview) recalcula cada lançamento.
 */
final class ReviewRules
{
    private const ITEM_LIMIT = 500;

    public function __construct(
        private readonly CategoryChangePlanner $planner,
    ) {}

    public function handle(): RuleReviewResult
    {
        $rules = $this->planner->activeRules();

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, list<CategoryChange>> $kept */
        $kept = [];

        foreach (ReviewGroup::cases() as $group) {
            $counts[$group->value] = 0;
            $kept[$group->value] = [];
        }

        $this->planner->reviewCandidates()
            ->lazyByIdDesc(500)
            ->each(function (Transaction $transaction) use ($rules, &$counts, &$kept): void {
                $change = $this->planner->forRules($transaction, $rules);

                if ($change === null) {
                    return;
                }

                $key = $change->group->value;
                $counts[$key]++;

                if (count($kept[$key]) < self::ITEM_LIMIT) {
                    $kept[$key][] = $change;
                }
            });

        // Todas as regras do usuário, inclusive desativadas: o grupo "stale"
        // mostra o nome da regra que tinha categorizado, mesmo desligada.
        /** @var array<int, string> $ruleNames */
        $ruleNames = Rule::query()->pluck('name', 'id')->all();

        $groups = array_map(fn (ReviewGroup $group) => new RuleReviewGroupResult(
            key: $group,
            count: $counts[$group->value],
            truncated: $counts[$group->value] > self::ITEM_LIMIT,
            items: $this->planner->items($kept[$group->value], $ruleNames),
        ), ReviewGroup::cases());

        return new RuleReviewResult($groups, CategoryChangePlanner::MAX_CHANGES);
    }
}
