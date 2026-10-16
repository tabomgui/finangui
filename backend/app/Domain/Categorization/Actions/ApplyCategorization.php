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
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Categoriza em lote os lançamentos ainda pendentes entre $transactionIds
 * (os demais são ignorados e contados em "skipped", nunca um erro — podem
 * ter sido categorizados por outro caminho, ou deixado de ser pendentes por
 * qualquer outro motivo — ex.: virado perna de transferência —, entre a
 * tela carregar e o usuário confirmar) e, opcionalmente, cria uma regra para
 * os próximos parecidos — tudo numa única transação: nada fica gravado se a
 * criação da regra falhar.
 *
 * Trava a linha do usuário (SELECT ... FOR UPDATE em users) e, com a mesma
 * trava, a seleção de pendentes (FOR UPDATE, ordenada por id para sempre
 * travar na mesma ordem) — duas chamadas concorrentes deste apply para o
 * mesmo usuário rodam em série, nunca categorizam a mesma transação duas
 * vezes nem leem um pendente que a outra está decidindo pular/categorizar.
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
    public function handle(int $userId, array $transactionIds, int $categoryId, ?array $ruleInput): CategorizationApplyResult
    {
        return DB::transaction(function () use ($userId, $transactionIds, $categoryId, $ruleInput): CategorizationApplyResult {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            /** @var Collection<int, Transaction> $pendingTransactions */
            $pendingTransactions = Transaction::query()
                ->pendingCategorization()
                ->whereIn('id', $transactionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'direction']);

            $pendingIds = $pendingTransactions->pluck('id')->all();

            if ($pendingIds !== []) {
                Transaction::query()->whereKey($pendingIds)->update([
                    'category_id' => $categoryId,
                    'categorized_by' => 'manual',
                ]);
            }

            $categorized = count($pendingIds);
            $skipped = count($transactionIds) - $categorized;

            $rule = $ruleInput !== null ? $this->createRuleFor($categoryId, $ruleInput) : null;

            $ruleApplied = $rule !== null && ($ruleInput['apply_to_pending'] ?? false)
                ? $this->applyToPending($rule, $this->selectionDirection($pendingTransactions))
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
     * Direção de verdade da seleção categorizada agora (nunca a de
     * $transactionIds original: um id "skipped" pode ser de qualquer
     * direção). Nula quando nada foi categorizado — não há seleção para
     * restringir, e applyToPending() roda sem o filtro de direção nesse
     * caso. ApplyCategorizationRequest já garante uma única direção em todo
     * o conjunto antes de chegar aqui; quem chama a action direto (fora do
     * request) é responsável por essa mesma garantia.
     *
     * @param  Collection<int, Transaction>  $pendingTransactions
     */
    private function selectionDirection(Collection $pendingTransactions): ?Direction
    {
        $directions = $pendingTransactions->map(fn (Transaction $transaction) => $transaction->direction)->unique();

        return $directions->count() === 1 ? $directions->first() : null;
    }

    /**
     * Aplica $rule aos demais pendentes do usuário (scopePendingCategorization
     * já garante category_id nulo — nunca sobrescreve quem já tem categoria;
     * os que acabaram de ser categorizados acima já saem desse escopo, sem
     * precisar de um whereNotIn à parte).
     *
     * Restringe à mesma direção da seleção (quando conhecida): uma regra
     * personalizada sem condição de direção (ex.: só "descrição contém X")
     * não pode, sozinha, jogar a categoria escolhida para a seleção de
     * saídas num pendente de entrada que só por coincidência bate na mesma
     * condição de texto.
     */
    private function applyToPending(Rule $rule, ?Direction $direction): int
    {
        $definition = RuleDefinition::fromRule($rule);
        $changed = 0;

        $query = Transaction::query()->pendingCategorization();

        if ($direction !== null) {
            $query->where('direction', $direction->value);
        }

        $query->lockForUpdate()
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
