<?php

namespace App\Domain\Banking\Support;

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
     */
    public static function toParsedRow(ProviderTransaction $transaction, bool $creditCard, int $line): ParsedRow
    {
        $meta = [];

        if ($transaction->billId !== null) {
            $meta['bill_id'] = $transaction->billId;
        }

        if ($transaction->categoryId !== null) {
            $meta['provider_category_id'] = $transaction->categoryId;
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
}
