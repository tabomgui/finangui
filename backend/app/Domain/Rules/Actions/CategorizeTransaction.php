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
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;

/**
 * Pipeline de categorização para lançamento novo sem categoria: regras
 * ativas (só a ação de categoria) e, se nenhuma categorizar, o histórico.
 *
 * `$rules`, `$usableCategoryIds` e `$historyMemo` são opcionais em
 * suggest()/handleImported() só para quem processa muitas transações de
 * uma vez (prévia e confirmação de importação): sem eles, cada chamada
 * consultaria de novo as regras ativas, se a categoria da regra ainda é
 * usável e o histórico — uma consulta a mais por linha num lote grande.
 * ImportPreview e IngestTransactions pré-carregam isso uma vez para o lote
 * inteiro (ver HistoryCategorizer::suggestMany() para o histórico em lote).
 */
final class CategorizeTransaction
{
    public function __construct(
        private readonly HistoryCategorizer $history,
        private readonly ApplyRuleOutcome $applyRuleOutcome,
    ) {}

    /**
     * @param  list<RuleDefinition>|null  $rules  regras ativas já carregadas; omitido, consulta na hora
     * @param  list<int>|null  $usableCategoryIds  ids de categoria usável (não arquivada) já carregados; omitido, consulta na hora
     * @param  array<string, int>|null  $historyMemo  mapa "description_key|direction" → categoria (ver HistoryCategorizer::suggestMany()); omitido, consulta o histórico na hora
     * @return array{category_id: int, categorized_by: string}|null
     */
    public function suggest(
        Transaction $transaction,
        ?array $rules = null,
        ?array $usableCategoryIds = null,
        ?array $historyMemo = null,
    ): ?array {
        $rules ??= $this->activeRuleDefinitions();

        $fromRules = $this->suggestFromRules($transaction, $rules, $usableCategoryIds);

        if ($fromRules !== null) {
            return $fromRules;
        }

        $descriptionKey = TextNormalizer::key($transaction->description);
        $categoryId = $historyMemo !== null
            ? ($historyMemo[self::historyKey($descriptionKey, $transaction->direction)] ?? null)
            : $this->history->suggest($descriptionKey, $transaction->direction);

        return $categoryId !== null ? ['category_id' => $categoryId, 'categorized_by' => 'history'] : null;
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
     * @param  list<RuleDefinition>|null  $rules  regras ativas já carregadas (ver suggest())
     * @param  array<string, int>|null  $historyMemo  mapa pré-calculado (ver suggest())
     */
    public function handleImported(Transaction $transaction, ?array $rules = null, ?array $historyMemo = null): void
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

        $descriptionKey = TextNormalizer::key($transaction->description);
        $categoryId = $historyMemo !== null
            ? ($historyMemo[self::historyKey($descriptionKey, $transaction->direction)] ?? null)
            : $this->history->suggest($descriptionKey, $transaction->direction);

        if ($categoryId === null) {
            return;
        }

        $transaction->category_id = $categoryId;
        $transaction->categorized_by = 'history';
        $transaction->save();
    }

    /**
     * Chave do mapa de histórico pré-calculado (ver HistoryCategorizer::suggestMany()).
     */
    public static function historyKey(string $descriptionKey, Direction $direction): string
    {
        return $descriptionKey.'|'.$direction->value;
    }

    /**
     * @param  list<RuleDefinition>  $rules
     * @param  list<int>|null  $usableCategoryIds
     * @return array{category_id: int, categorized_by: string}|null
     */
    private function suggestFromRules(Transaction $transaction, array $rules, ?array $usableCategoryIds): ?array
    {
        $context = new RuleContext(
            hasCategory: false,
            categoryManual: false,
            descriptionLocked: true,
            overwrite: false,
            onlyCategory: true,
        );

        $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), $rules, $context);

        if ($outcome->categoryId === null) {
            return null;
        }

        if (! $this->isUsableCategory($outcome->categoryId, $usableCategoryIds)) {
            return null;
        }

        return ['category_id' => $outcome->categoryId, 'categorized_by' => "rule:{$outcome->categoryRuleId}"];
    }

    /**
     * @param  list<int>|null  $usableCategoryIds
     */
    private function isUsableCategory(int $categoryId, ?array $usableCategoryIds): bool
    {
        if ($usableCategoryIds !== null) {
            return in_array($categoryId, $usableCategoryIds, true);
        }

        return Category::query()->whereKey($categoryId)->usable()->exists();
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
