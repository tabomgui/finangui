<?php

namespace App\Domain\Transactions\Queries;

use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

final class TransactionQuery
{
    /**
     * @param  array<string, mixed>  $filters  dados já validados
     * @return Builder<Transaction>
     */
    public static function filtered(array $filters): Builder
    {
        return Transaction::query()
            ->with(['account', 'category.parent', 'tags', 'installmentPlan', 'recurrence'])
            ->when($filters['account_id'] ?? null, fn (Builder $q, $id) => $q->where('account_id', $id))
            ->when($filters['statement_id'] ?? null, fn (Builder $q, $id) => $q->where('statement_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => ($filters['category_exact'] ?? false)
                ? $q->where('category_id', $id)
                : $q->whereIn(
                    'category_id',
                    Category::query()->where(fn ($w) => $w->where('id', $id)->orWhere('parent_id', $id))->select('id'),
                ))
            ->when($filters['no_category'] ?? false, fn (Builder $q) => $q->whereNull('category_id'))
            ->when($filters['tag_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $id)))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->where('date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->where('date', '<=', $date))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['direction'] ?? null, fn (Builder $q, $direction) => $q->where('direction', $direction))
            ->when($filters['currency'] ?? null, fn (Builder $q, $currency) => $q->where('currency', $currency))
            ->when($filters['reportable'] ?? false, fn (Builder $q) => $q->reportable())
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('description', 'ilike', $like)->orWhere('original_description', 'ilike', $like));
            })
            ->orderByDesc('date')
            ->orderByDesc('id');
    }
}
