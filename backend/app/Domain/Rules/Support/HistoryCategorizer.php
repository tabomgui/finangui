<?php

namespace App\Domain\Rules\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;

/**
 * Sugestão de categoria pelo histórico: a mais usada nas transações do
 * usuário com a mesma chave de descrição e a mesma direção; empate: a usada
 * mais recentemente.
 */
final class HistoryCategorizer
{
    /**
     * Ignora pernas de transferência, ignoradas, não lançadas (projetada ou
     * pendente) e categorias arquivadas. Um parcelamento conta como um uso
     * só, não uma parcela por uso: agrupa as parcelas do mesmo plano por
     * "p{plan_id}" e cada transação solta por "t{id}", e conta distinto.
     */
    public function suggest(string $descriptionKey, Direction $direction): ?int
    {
        if ($descriptionKey === '') {
            return null;
        }

        $row = Transaction::query()
            ->where('description_key', $descriptionKey)
            ->where('direction', $direction)
            ->where('status', TransactionStatus::Posted)
            ->whereNotNull('category_id')
            ->whereNull('transfer_id')
            ->where('is_ignored', false)
            ->whereHas('category', fn ($query) => $query->where('is_archived', false))
            ->groupBy('category_id')
            ->selectRaw("category_id, COUNT(DISTINCT COALESCE('p' || installment_plan_id::text, 't' || id::text)) AS uses, MAX(date) AS last_used")
            ->orderByDesc('uses')
            ->orderByDesc('last_used')
            ->limit(1)
            ->first();

        return $row !== null ? (int) $row->category_id : null;
    }
}
