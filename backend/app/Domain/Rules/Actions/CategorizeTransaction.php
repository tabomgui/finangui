<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Rules\Support\RuleEngine;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Models\Transaction;

/**
 * Pipeline de categorização para lançamento novo sem categoria: regras
 * ativas (só a ação de categoria) e, se nenhuma categorizar, o histórico.
 */
final class CategorizeTransaction
{
    public function __construct(
        private readonly HistoryCategorizer $history,
        private readonly ApplyRuleOutcome $applyRuleOutcome,
    ) {}

    /**
     * @return array{category_id: int, categorized_by: string}|null
     */
    public function suggest(Transaction $transaction): ?array
    {
        $rules = Rule::query()->where('is_active', true)->ordered()->get()
            ->map(RuleDefinition::fromRule(...))
            ->all();

        $context = new RuleContext(
            hasCategory: false,
            categoryManual: false,
            descriptionLocked: true,
            overwrite: false,
            onlyCategory: true,
        );

        $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), $rules, $context);

        if ($outcome->categoryId !== null && Category::query()->whereKey($outcome->categoryId)->usable()->exists()) {
            return ['category_id' => $outcome->categoryId, 'categorized_by' => "rule:{$outcome->categoryRuleId}"];
        }

        $categoryId = $this->history->suggest(TextNormalizer::key($transaction->description), $transaction->direction);

        if ($categoryId !== null) {
            return ['category_id' => $categoryId, 'categorized_by' => 'history'];
        }

        return null;
    }

    /**
     * Aplica suggest() se a transação não tem categoria e não é perna de
     * transferência.
     */
    public function handle(Transaction $transaction): void
    {
        if ($transaction->category_id !== null || $transaction->isTransferLeg()) {
            return;
        }

        $suggestion = $this->suggest($transaction);

        if ($suggestion === null) {
            return;
        }

        $transaction->category_id = $suggestion['category_id'];
        $transaction->categorized_by = $suggestion['categorized_by'];
    }

    /**
     * Lançamento importado já salvo, sem categoria: regras ativas com todas
     * as ações (via ApplyRuleOutcome) e, se nenhuma categorizar, o
     * histórico. Pernas de transferência não existem na importação, mas o
     * guard fica por simetria com handle().
     *
     * @param  list<RuleDefinition>|null  $rules  regras ativas já carregadas
     *                                            (evita reconsultar por linha num lote, ver IngestTransactions); omitido, carrega na hora, sem memoizar.
     */
    public function handleImported(Transaction $transaction, ?array $rules = null): void
    {
        if ($transaction->category_id !== null || $transaction->isTransferLeg()) {
            return;
        }

        $rules ??= $this->activeRuleDefinitions();

        $context = RuleContext::forExisting($transaction, overwrite: false);
        $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), $rules, $context);

        $this->applyRuleOutcome->handle($transaction, $outcome);

        // Lido de novo (não reaproveita a narrowing de category_id da guarda
        // acima): ApplyRuleOutcome::handle() pode ter categorizado agora.
        if ($transaction->categorized_by !== null) {
            return;
        }

        $categoryId = $this->history->suggest(TextNormalizer::key($transaction->description), $transaction->direction);

        if ($categoryId === null) {
            return;
        }

        $transaction->category_id = $categoryId;
        $transaction->categorized_by = 'history';
        $transaction->save();
    }

    /**
     * @return list<RuleDefinition>
     */
    private function activeRuleDefinitions(): array
    {
        return Rule::query()->where('is_active', true)->ordered()->get()
            ->map(RuleDefinition::fromRule(...))
            ->all();
    }
}
