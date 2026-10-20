<?php

namespace App\Domain\Rules\Queries;

use App\Domain\Rules\Data\RuleReviewGroupResult;
use App\Domain\Rules\Data\RuleReviewResult;
use App\Domain\Rules\Enums\ReviewGroup;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Rules\Support\MostRecentChanges;
use App\Domain\Transactions\Models\Transaction;

/**
 * Revisão de todas as regras ativas contra os lançamentos já existentes,
 * só a categoria (ver CategoryChangePlanner::forRules()): regras carregadas
 * uma vez, lançamentos lidos em lotes. Contagem completa e os 500 itens mais
 * recentes (pela data do lançamento) por grupo. Nunca grava nada; a aplicação
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
        /** @var array<string, MostRecentChanges> $kept */
        $kept = [];

        foreach (ReviewGroup::cases() as $group) {
            $counts[$group->value] = 0;
            $kept[$group->value] = new MostRecentChanges(self::ITEM_LIMIT);
        }

        $this->planner->reviewCandidates()
            ->lazyById(500)
            ->each(function (Transaction $transaction) use ($rules, &$counts, &$kept): void {
                $change = $this->planner->forRules($transaction, $rules);

                if ($change === null) {
                    return;
                }

                $key = $change->group->value;
                $counts[$key]++;
                $kept[$key]->add($change);
            });

        // Todas as regras do usuário, inclusive desativadas: o grupo "stale"
        // mostra o nome da regra que tinha categorizado, mesmo desligada, e
        // conflict mostra a regra que deu a categoria atual.
        /** @var array<int, Rule> $allRules */
        $allRules = Rule::query()->get(['id', 'name', 'is_active'])->keyBy('id')->all();

        $groups = array_map(fn (ReviewGroup $group) => new RuleReviewGroupResult(
            key: $group,
            count: $counts[$group->value],
            truncated: $counts[$group->value] > self::ITEM_LIMIT,
            items: $this->planner->items($kept[$group->value]->all(), $allRules),
        ), ReviewGroup::cases());

        return new RuleReviewResult($groups, CategoryChangePlanner::MAX_CHANGES);
    }
}
