<?php

namespace App\Domain\Categorization\Actions;

use App\Domain\Categorization\Data\CategorizationApplyResult;
use App\Domain\Rules\Actions\ApplyRuleOutcome;
use App\Domain\Rules\Actions\CreateRule;
use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\RuleEngine;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Categoriza em lote os lançamentos ainda pendentes entre $transactionIds
 * (os demais são ignorados e contados em "skipped", nunca um erro — podem
 * ter sido categorizados por outro caminho entre a tela carregar e o
 * usuário confirmar) e, opcionalmente, cria uma regra para os próximos
 * parecidos — tudo numa única transação: nada fica gravado se a criação da
 * regra falhar.
 *
 * O match da regra recém-criada sobre os demais pendentes ("apply_to_pending")
 * usa o mesmo App\Domain\Rules\Support\RuleEngine/RuleMatcher da aplicação
 * retroativa de uma regra já salva (App\Domain\Rules\Jobs\ApplyRuleRetroactively),
 * só que síncrono — o resultado precisa valer na hora, para a tela refletir
 * sem esperar uma fila.
 */
final class ApplyCategorization
{
    public function __construct(
        private readonly CreateRule $createRule,
        private readonly ApplyRuleOutcome $applyRuleOutcome,
    ) {}

    /**
     * @param  list<int>  $transactionIds
     * @param  array{name: string, match: string, conditions: list<array<string, mixed>>, apply_to_pending?: bool}|null  $ruleInput  dados já validados (ApplyCategorizationRequest)
     */
    public function handle(array $transactionIds, int $categoryId, ?array $ruleInput): CategorizationApplyResult
    {
        return DB::transaction(function () use ($transactionIds, $categoryId, $ruleInput): CategorizationApplyResult {
            $pendingTransactions = Transaction::query()
                ->pendingCategorization()
                ->whereIn('id', $transactionIds)
                ->get();

            foreach ($pendingTransactions as $transaction) {
                $transaction->update(['category_id' => $categoryId, 'categorized_by' => 'manual']);
            }

            $categorized = $pendingTransactions->count();
            $skipped = count($transactionIds) - $categorized;

            $rule = $ruleInput !== null ? $this->createRuleFor($categoryId, $ruleInput) : null;

            $ruleApplied = $rule !== null && ($ruleInput['apply_to_pending'] ?? false)
                ? $this->applyToPending($rule, $pendingTransactions->pluck('id')->all())
                : null;

            return new CategorizationApplyResult(
                categorized: $categorized,
                skipped: $skipped,
                ruleApplied: $ruleApplied,
                ruleId: $rule?->id,
                ruleName: $rule?->name,
            );
        });
    }

    /**
     * @param  array{name: string, match: string, conditions: list<array<string, mixed>>}  $ruleInput
     */
    private function createRuleFor(int $categoryId, array $ruleInput): Rule
    {
        return $this->createRule->handle([
            'name' => $ruleInput['name'],
            'match' => $ruleInput['match'],
            'conditions' => $ruleInput['conditions'],
            'actions' => [['type' => 'set_category', 'category_id' => $categoryId]],
        ]);
    }

    /**
     * Aplica $rule aos pendentes do usuário que ainda não foram categorizados
     * por este apply (scopePendingCategorization já garante category_id
     * nulo — nunca sobrescreve quem já tem categoria).
     *
     * @param  list<int>  $excludeIds
     */
    private function applyToPending(Rule $rule, array $excludeIds): int
    {
        $definition = RuleDefinition::fromRule($rule);
        $changed = 0;

        Transaction::query()
            ->pendingCategorization()
            ->whereNotIn('id', $excludeIds)
            ->lazyById(500)
            ->each(function (Transaction $transaction) use ($definition, &$changed): void {
                $context = RuleContext::forExisting($transaction, overwrite: false);
                $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), [$definition], $context);

                if ($this->applyRuleOutcome->handle($transaction, $outcome)) {
                    $changed++;
                }
            });

        return $changed;
    }
}
