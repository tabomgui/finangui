<?php

namespace App\Domain\Imports\Support;

use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use BackedEnum;
use Carbon\CarbonImmutable;

/**
 * Aplica um conjunto de atributos numa transação e devolve só os valores
 * antigos das chaves que de fato mudaram — a base do "undo" que
 * IngestTransactions grava por linha (ver RevertImportBatch, que restaura
 * exatamente essas chaves). Não salva: quem chama decide quando.
 */
final class UndoSnapshot
{
    /**
     * @param  array<string, mixed>  $attributes  chave do model => novo valor, no formato aceito pelo cast
     * @return array<string, mixed> valor antigo (já "achatado": enum->value, Money->cents, data->toDateString()) de cada chave que mudou
     */
    public static function applyAndDiff(Transaction $transaction, array $attributes): array
    {
        $changed = [];

        foreach ($attributes as $key => $value) {
            $old = self::flatten($transaction->getAttribute($key));
            $transaction->setAttribute($key, $value);
            $new = self::flatten($transaction->getAttribute($key));

            if ($old !== $new) {
                $changed[$key] = $old;
            }
        }

        return $changed;
    }

    private static function flatten(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof Money => $value->cents,
            $value instanceof CarbonImmutable => $value->toDateString(),
            default => $value,
        };
    }
}
