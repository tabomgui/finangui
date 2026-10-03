<?php

namespace App\Domain\Rules\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;

/**
 * Sugestão de categoria pelo histórico: a mais usada nas transações do
 * usuário com a mesma chave de descrição e a mesma direção; empate: a usada
 * mais recentemente.
 */
final class HistoryCategorizer
{
    /**
     * Ignora pernas de transferência, ignoradas e categorias arquivadas.
     */
    public function suggest(string $descriptionKey, Direction $direction): ?int
    {
        if ($descriptionKey === '') {
            return null;
        }

        $row = Transaction::query()
            ->where('description_key', $descriptionKey)
            ->where('direction', $direction)
            ->whereNotNull('category_id')
            ->whereNull('transfer_id')
            ->where('is_ignored', false)
            ->whereHas('category', fn ($query) => $query->where('is_archived', false))
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS uses, MAX(date) AS last_used')
            ->orderByDesc('uses')
            ->orderByDesc('last_used')
            ->limit(1)
            ->first();

        return $row !== null ? (int) $row->category_id : null;
    }
}
