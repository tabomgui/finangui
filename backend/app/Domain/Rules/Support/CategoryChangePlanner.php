<?php

namespace App\Domain\Rules\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Actions\ApplyRuleOutcome;
use App\Domain\Rules\Data\CategoryChange;
use App\Domain\Rules\Data\CategoryChangeItem;
use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleOutcome;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Enums\CategorySource;
use App\Domain\Rules\Enums\ReconcileGroup;
use App\Domain\Rules\Enums\ReviewGroup;
use App\Domain\Rules\Models\Rule;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Decide, só para a categoria, o que mudaria num lançamento já existente
 * quando uma regra é editada (forRule(), App\Domain\Rules\Queries\ReconcileRule)
 * ou quando todas as regras ativas são revistas juntas (forRules(),
 * App\Domain\Rules\Queries\ReviewRules) — a prévia e a aplicação de cada
 * fluxo chamam o mesmo método, que é recalculado na aplicação sob trava.
 *
 * O casamento é sempre o de RuleEngine/RuleMatcher, com RuleContext::categoryOnly()
 * (a categoria que as regras dariam, ignorando a atual), e quem diz se a
 * categoria pode de fato ser gravada (existe, não arquivada, diferente da
 * atual) é ApplyRuleOutcome::changes() — o mesmo que grava (apply()).
 *
 * Nunca muda: lançamento categorizado à mão (com categoria), lançamento fora de
 * Transaction::scopeEligibleForRules() (quem chama já filtra a consulta) e
 * categoria de transferência (ela mesma ou pelo pai) ao tirá-la, ou na
 * revisão ao trocá-la por uma comum: sem ela o lançamento voltaria a
 * reportable() e contaria em dobro (ex.: o pagamento de fatura que quita
 * compras já contadas no cartão), como em
 * Transaction::scopeCategorizedByProvider(). Na reconciliação, trocar a
 * categoria dos lançamentos da própria regra editada (recategorize) segue a
 * nova categoria dela, seja qual for: foi o usuário quem a mudou.
 */
final class CategoryChangePlanner
{
    /** Máximo de lançamentos alterados por aplicação (reconciliação ou revisão). */
    public const MAX_CHANGES = 5000;

    /**
     * O que o scan precisa (RuleSubject, estado da categoria, perna de
     * transferência para ApplyRuleOutcome::changes()): nunca `raw`.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'account_id', 'date', 'amount', 'direction', 'description', 'original_description',
        'payee', 'notes', 'category_id', 'categorized_by', 'transfer_id',
    ];

    /** @var array<int, true>|null */
    private ?array $transferCategoryIds = null;

    public function __construct(
        private readonly ApplyRuleOutcome $apply,
    ) {}

    /**
     * Candidatos da reconciliação de uma regra: sem categoria, ou
     * categorizados por ela.
     *
     * @return Builder<Transaction>
     */
    public function reconcileCandidates(int $ruleId): Builder
    {
        return Transaction::query()
            ->eligibleForRules()
            ->where(fn (Builder $q) => $q->whereNull('transactions.category_id')
                ->orWhere('transactions.categorized_by', "rule:{$ruleId}"))
            ->select(self::COLUMNS);
    }

    /**
     * Candidatos da revisão de regras: todo lançamento elegível.
     *
     * @return Builder<Transaction>
     */
    public function reviewCandidates(): Builder
    {
        return Transaction::query()->eligibleForRules()->select(self::COLUMNS);
    }

    /**
     * Regras ativas na ordem do motor (prioridade, id).
     *
     * @return list<RuleDefinition>
     */
    public function activeRules(): array
    {
        return Rule::query()->where('is_active', true)->ordered()->get()
            ->map(RuleDefinition::fromRule(...))
            ->values()
            ->all();
    }

    /**
     * A regra recém-editada ($ruleId), avaliada junto com todas as regras
     * ativas ($rules, de activeRules()) na ordem do motor, como na revisão
     * (forRules()) — primeira que define categoria vence:
     * - sem categoria e a regra editada é a vencedora: fill;
     * - categorizado por ela, ela ainda vence e a categoria mudou: recategorize;
     * - categorizado por ela e nenhuma regra ativa dá categoria: clear.
     * Se outra regra ativa vence agora, o lançamento fica fora da
     * reconciliação (a revisão de regras cobre como conflict). Desativada,
     * a regra editada nem entra em $rules: nunca vence, e só cai em clear o
     * que nenhuma regra ativa categoriza — o mesmo que a revisão chama de
     * stale. Assim, aplicar a reconciliação nunca deixa algo que a revisão
     * proponha desfazer.
     *
     * "Sem categoria" inclui o que tem categorized_by = manual mas
     * category_id nulo (a categoria escolhida à mão foi excluída depois):
     * não há mais categoria à mão nenhuma a preservar.
     *
     * @param  list<RuleDefinition>  $rules
     */
    public function forRule(Transaction $transaction, int $ruleId, array $rules): ?CategoryChange
    {
        if (self::isManual($transaction)) {
            return null;
        }

        $outcome = $this->evaluate($transaction, $rules);
        $target = $this->target($transaction, $outcome);
        $wins = $outcome->categoryRuleId === $ruleId;

        if ($transaction->category_id === null) {
            return $wins && $target !== null
                ? new CategoryChange($transaction, ReconcileGroup::Fill, $target, $ruleId, $outcome)
                : null;
        }

        if ($transaction->categorized_by !== "rule:{$ruleId}") {
            return null;
        }

        if ($wins && $target !== null) {
            return new CategoryChange($transaction, ReconcileGroup::Recategorize, $target, $ruleId, $outcome);
        }

        // Categoria igual à atual, arquivada/excluída ou de outra regra que
        // agora vence: nada a fazer aqui — só tira quando nenhuma regra
        // ativa dá categoria.
        if ($outcome->categoryId === null && $this->canClear($transaction)) {
            return new CategoryChange($transaction, ReconcileGroup::Clear, null, $ruleId, null);
        }

        return null;
    }

    /**
     * Todas as regras ativas juntas ($rules, de activeRules()), primeira que
     * define categoria vence:
     * - sem categoria e as regras dão uma: fill;
     * - categoria automática (histórico, banco, regra) diferente: conflict;
     * - categorizado por uma regra e nenhuma regra ativa dá categoria: stale;
     * - à mão com categoria diferente: manual (só informativo).
     * À mão sem categoria (categoria excluída) conta como sem categoria,
     * como em forRule().
     *
     * @param  list<RuleDefinition>  $rules
     */
    public function forRules(Transaction $transaction, array $rules): ?CategoryChange
    {
        $outcome = $this->evaluate($transaction, $rules);
        $target = $this->target($transaction, $outcome);
        $categorizedBy = (string) $transaction->categorized_by;

        if (self::isManual($transaction)) {
            return $target !== null
                ? new CategoryChange($transaction, ReviewGroup::Manual, $target, $outcome->categoryRuleId, null)
                : null;
        }

        if ($transaction->category_id === null) {
            return $target !== null
                ? new CategoryChange($transaction, ReviewGroup::Fill, $target, $outcome->categoryRuleId, $outcome)
                : null;
        }

        $byRule = self::ruleIdOf($categorizedBy);

        if ($target !== null) {
            $automatic = $byRule !== null || in_array($categorizedBy, ['history', 'pluggy'], true);

            // Trocar uma categoria de transferência por uma comum tem o mesmo
            // efeito de tirá-la (ver canClear()): só entre transferências.
            return $automatic && ($this->canClear($transaction) || $this->isTransferCategory($target))
                ? new CategoryChange($transaction, ReviewGroup::Conflict, $target, $outcome->categoryRuleId, $outcome)
                : null;
        }

        if ($byRule !== null && $outcome->categoryId === null && $this->canClear($transaction)) {
            return new CategoryChange($transaction, ReviewGroup::Stale, null, $byRule, null);
        }

        return null;
    }

    /**
     * Grava a mudança: categoria nova por ApplyRuleOutcome (categorized_by
     * vira rule:{id da regra que decidiu}); tirar a categoria zera
     * category_id e categorized_by, sem mexer na descrição (nem na
     * description_key).
     */
    public function apply(CategoryChange $change): bool
    {
        if ($change->group === ReviewGroup::Manual) {
            throw new LogicException('Categoria definida à mão nunca muda por regra.');
        }

        $transaction = $change->transaction;

        if ($change->categoryId !== null) {
            return $change->outcome !== null && $this->apply->handle($transaction, $change->outcome);
        }

        $transaction->category_id = null;
        $transaction->categorized_by = null;
        $transaction->save();

        return true;
    }

    /**
     * Linhas para exibir, mais recentes primeiro (data, depois id). Uma
     * consulta só para os nomes das contas. $rules (id → regra, inclusive
     * desativadas): regra ausente do mapa (excluída) sai sem
     * rule_id/rule_name/rule_active e sem current_rule_name.
     *
     * @param  list<CategoryChange>  $changes
     * @param  array<int, Rule>  $rules
     * @return list<CategoryChangeItem>
     */
    public function items(array $changes, array $rules = []): array
    {
        if ($changes === []) {
            return [];
        }

        usort($changes, fn (CategoryChange $a, CategoryChange $b): int => [$b->transaction->date, $b->transaction->id] <=> [$a->transaction->date, $a->transaction->id]);

        $accountIds = array_values(array_unique(array_map(fn (CategoryChange $c) => $c->transaction->account_id, $changes)));
        /** @var array<int, string> $accountNames */
        $accountNames = Account::query()->whereKey($accountIds)->pluck('name', 'id')->all();

        return array_map(function (CategoryChange $change) use ($accountNames, $rules): CategoryChangeItem {
            $transaction = $change->transaction;
            $rule = $change->ruleId !== null ? ($rules[$change->ruleId] ?? null) : null;
            $source = $transaction->category_id !== null ? CategorySource::fromCategorizedBy($transaction->categorized_by) : null;
            $currentRuleId = $source === CategorySource::Rule ? self::ruleIdOf((string) $transaction->categorized_by) : null;
            $currentRule = $currentRuleId !== null ? ($rules[$currentRuleId] ?? null) : null;

            return new CategoryChangeItem(
                transactionId: $transaction->id,
                date: $transaction->date->toDateString(),
                description: $transaction->description,
                accountName: $accountNames[$transaction->account_id] ?? '',
                amount: $transaction->amount->cents,
                direction: $transaction->direction,
                currentCategoryId: $transaction->category_id,
                newCategoryId: $change->categoryId,
                ruleId: $rule?->id,
                ruleName: $rule?->name,
                ruleActive: $rule?->is_active,
                currentSource: $source,
                currentRuleName: $currentRule?->name,
            );
        }, $changes);
    }

    /**
     * Id da regra em categorized_by = "rule:{id}"; nulo para os demais.
     */
    public static function ruleIdOf(string $categorizedBy): ?int
    {
        if (! str_starts_with($categorizedBy, 'rule:')) {
            return null;
        }

        $id = substr($categorizedBy, 5);

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Categoria definida à mão que ainda existe: nunca muda por regra.
     */
    private static function isManual(Transaction $transaction): bool
    {
        return $transaction->categorized_by === 'manual' && $transaction->category_id !== null;
    }

    /**
     * @param  list<RuleDefinition>  $rules
     */
    private function evaluate(Transaction $transaction, array $rules): RuleOutcome
    {
        return RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), $rules, RuleContext::categoryOnly());
    }

    /**
     * Categoria que as regras dão e que de fato mudaria o lançamento (nula
     * quando as regras não dão nenhuma, quando é a atual, ou quando a
     * categoria foi arquivada/excluída depois de a regra ser salva).
     */
    private function target(Transaction $transaction, RuleOutcome $outcome): ?int
    {
        if ($outcome->categoryId === null) {
            return null;
        }

        return $this->apply->changes($transaction, $outcome)['category_id'] ?? null;
    }

    private function canClear(Transaction $transaction): bool
    {
        return $transaction->category_id === null || ! $this->isTransferCategory($transaction->category_id);
    }

    /**
     * Categoria de transferência, ela mesma ou pelo pai (fora de
     * reportable()). Carregadas uma vez por instância.
     */
    private function isTransferCategory(int $categoryId): bool
    {
        $this->transferCategoryIds ??= Category::query()
            ->with('parent:id,is_transfer')
            ->get(['id', 'parent_id', 'is_transfer'])
            ->filter(fn (Category $category) => $category->isTransferEffective())
            ->mapWithKeys(fn (Category $category) => [$category->id => true])
            ->all();

        return isset($this->transferCategoryIds[$categoryId]);
    }
}
