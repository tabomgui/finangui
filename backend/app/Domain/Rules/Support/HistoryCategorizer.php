<?php

namespace App\Domain\Rules\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Sugestão de categoria pelo histórico: a mais usada nas transações do
 * usuário com a mesma chave de descrição e a mesma direção; empate: a usada
 * mais recentemente.
 */
final class HistoryCategorizer
{
    private const CHUNK_SIZE = 1000;

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

    /**
     * Mesma regra do suggest(), mas para muitas combinações
     * description_key+direction de uma vez — uma consulta por grupo de até
     * 1000 pares (`ROW_NUMBER() OVER` escolhendo, por par, a categoria com
     * mais usos e, no empate, a mais recente), em vez de uma consulta por
     * linha nova de um lote de importação.
     *
     * @param  list<array{description_key: string, direction: string}>  $pairs
     * @return array<string, int> categoria por "description_key|direction" (ver CategorizeTransaction::historyKey())
     */
    public function suggestMany(array $pairs): array
    {
        $pairs = array_values(array_unique(
            array_filter($pairs, fn (array $pair) => $pair['description_key'] !== ''),
            SORT_REGULAR,
        ));

        if ($pairs === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($pairs, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $bindings = [];
            foreach ($chunk as $pair) {
                $bindings[] = $pair['description_key'];
                $bindings[] = $pair['direction'];
            }

            $ranked = Transaction::query()
                ->select(['description_key', 'direction', 'category_id'])
                ->selectRaw("COUNT(DISTINCT COALESCE('p' || installment_plan_id::text, 't' || id::text)) AS uses")
                ->selectRaw('MAX(date) AS last_used')
                ->selectRaw(
                    'ROW_NUMBER() OVER ('
                    .'PARTITION BY description_key, direction '
                    ."ORDER BY COUNT(DISTINCT COALESCE('p' || installment_plan_id::text, 't' || id::text)) DESC, MAX(date) DESC"
                    .') AS rn'
                )
                ->where('status', TransactionStatus::Posted)
                ->whereNotNull('category_id')
                ->whereNull('transfer_id')
                ->where('is_ignored', false)
                ->whereHas('category', fn ($query) => $query->where('is_archived', false))
                ->whereRaw("(description_key, direction) IN ({$placeholders})", $bindings)
                ->groupBy('description_key', 'direction', 'category_id');

            $rows = DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->get(['description_key', 'direction', 'category_id']);

            foreach ($rows as $row) {
                $result[$row->description_key.'|'.$row->direction] = (int) $row->category_id;
            }
        }

        return $result;
    }
}
