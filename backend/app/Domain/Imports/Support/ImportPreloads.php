<?php

namespace App\Domain\Imports\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Rules\Support\TextNormalizer;

/**
 * As consultas que App\Domain\Imports\Actions\IngestTransactions faz uma
 * única vez para o lote inteiro, em vez de uma por linha nova: a fatura do
 * banco (meta.bill_id), as categorias ativas do usuário por nome (para
 * App\Domain\Banking\Support\ProviderCategoryMatcher) e os pares de
 * histórico (para HistoryCategorizer::suggestMany()). Puro em relação ao
 * resultado — só lê, nunca grava.
 */
final class ImportPreloads
{
    /**
     * Uma consulta para toda fatura local (CardStatement) cujo external_id
     * aparece em meta.bill_id de alguma linha nova deste lote — em vez de
     * uma consulta por linha. Contas que não são cartão nunca têm fatura,
     * então nem tenta.
     *
     * @param  list<RowDecision>  $decisions
     * @return array<string, int> external_id → id do CardStatement
     */
    public static function billStatements(Account $account, array $decisions): array
    {
        if (! $account->isCreditCard()) {
            return [];
        }

        $billIds = [];

        foreach ($decisions as $decision) {
            if ($decision->outcome !== RowOutcome::New) {
                continue;
            }

            $billId = $decision->row->meta['bill_id'] ?? null;

            if (is_string($billId)) {
                $billIds[$billId] = true;
            }
        }

        if ($billIds === []) {
            return [];
        }

        return CardStatement::query()
            ->where('account_id', $account->id)
            ->whereIn('external_id', array_keys($billIds))
            ->pluck('id', 'external_id')
            ->all();
    }

    /**
     * Uma consulta para toda categoria ativa do usuário, agrupada pelo nome
     * normalizado (ver TextNormalizer::key()) — em vez de uma consulta por
     * linha, e sem tentar adivinhar de antemão quais nomes o
     * ProviderCategoryMatcher vai precisar (ele tenta sinônimo da folha, do
     * pai, e os nomes exatos — filtrar a consulta por nome arriscaria
     * perder um match por diferença de acento/caixa entre o nome do
     * provedor e o nome que o usuário deu à categoria). Só consulta quando
     * alguma linha nova do lote de fato carrega meta.provider_category.
     *
     * @param  list<RowDecision>  $decisions
     * @return array<string, list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>>
     */
    public static function usableCategoriesByName(array $decisions): array
    {
        $needsLookup = false;

        foreach ($decisions as $decision) {
            if ($decision->outcome === RowOutcome::New && isset($decision->row->meta['provider_category'])) {
                $needsLookup = true;
                break;
            }
        }

        if (! $needsLookup) {
            return [];
        }

        $byName = [];

        foreach (Category::query()->usable()->get() as $category) {
            $byName[TextNormalizer::key($category->name)][] = [
                'id' => $category->id,
                'kind' => $category->kind->value,
                'is_transfer' => $category->is_transfer,
                'has_parent' => $category->parent_id !== null,
            ];
        }

        return $byName;
    }

    /**
     * @param  list<RowDecision>  $decisions
     * @return list<array{description_key: string, direction: string}>
     */
    public static function historyPairs(array $decisions): array
    {
        $pairs = [];

        foreach ($decisions as $decision) {
            if ($decision->outcome !== RowOutcome::New) {
                continue;
            }

            $pairs[] = [
                'description_key' => TextNormalizer::key($decision->row->description),
                'direction' => $decision->row->direction->value,
            ];
        }

        return $pairs;
    }
}
