<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Transactions\Enums\Direction;

/**
 * Converte uma transação já traduzida do provedor (ProviderTransaction) para
 * o vocabulário da ingestão de importação (ParsedRow) — o sync bancário
 * reaproveita o mesmo `IngestTransactions` de um arquivo, em vez de duplicar
 * dedup/parcela/categorização.
 */
final class TransactionMapper
{
    /**
     * `installment` só é levado em cartão e na saída: fora de cartão não há
     * parcelamento, e uma entrada com `creditCardMetadata` (ex.: estorno de
     * parcela) não projeta plano nenhum — vira lançamento simples.
     *
     * @param  array<string, ProviderCategory>  $categoriesById  categorias do provedor indexadas por id (ver BankProvider::categories()); o mapper não busca nada por conta própria — fica puro, quem chama resolve a categoria efetiva depois (ver ProviderCategoryMatcher)
     */
    public static function toParsedRow(ProviderTransaction $transaction, bool $creditCard, int $line, array $categoriesById): ParsedRow
    {
        $meta = [];

        if ($transaction->billId !== null) {
            $meta['bill_id'] = $transaction->billId;
        }

        $providerCategory = self::providerCategory($transaction->categoryId, $categoriesById);

        if ($providerCategory !== null) {
            $meta['provider_category'] = $providerCategory;
        }

        return new ParsedRow(
            line: $line,
            date: $transaction->date,
            amount: $transaction->amountCents,
            direction: $transaction->direction,
            description: $transaction->description,
            externalId: $transaction->id,
            installment: $creditCard && $transaction->direction === Direction::Out ? $transaction->installment : null,
            pending: $transaction->pending,
            meta: $meta,
        );
    }

    /**
     * Nome (já traduzido) da categoria do provedor e do pai dela, se tiver
     * — a "folha" e o nível acima, para o ProviderCategoryMatcher tentar os
     * dois na hora de casar com uma categoria do usuário.
     *
     * @param  array<string, ProviderCategory>  $categoriesById
     * @return array{name: string, parent: string|null}|null
     */
    private static function providerCategory(?string $categoryId, array $categoriesById): ?array
    {
        if ($categoryId === null || ! isset($categoriesById[$categoryId])) {
            return null;
        }

        $category = $categoriesById[$categoryId];
        $parentName = $category->parentId !== null ? ($categoriesById[$category->parentId]->name ?? null) : null;

        return ['name' => $category->name, 'parent' => $parentName];
    }
}
