<?php

namespace App\Http\Requests\Budgets\Concerns;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use Illuminate\Validation\Validator;

/**
 * Só categoria de despesa, ativa e fora de transferência (nem ela, nem o
 * pai) pode receber orçamento. category_id em si (existe e é do usuário)
 * já é checado em rules() por cada request; aqui só o resto da regra.
 */
trait ValidatesBudgetableCategory
{
    private function validateBudgetableCategory(Validator $validator): void
    {
        if (! $this->filled('category_id')) {
            return;
        }

        $category = Category::query()->with('parent')->find($this->input('category_id'));

        if ($category === null) {
            return;
        }

        if ($category->kind !== CategoryKind::Expense) {
            $validator->errors()->add('category_id', 'Só categorias de despesa podem ter orçamento.');

            return;
        }

        if ($category->is_archived) {
            $validator->errors()->add('category_id', 'Categoria arquivada.');

            return;
        }

        // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que parent_id/parent são nullable; em runtime uma categoria raiz não tem parent)
        if ($category->is_transfer || ($category->parent?->is_transfer ?? false)) {
            $validator->errors()->add('category_id', 'Categoria de transferência não pode ter orçamento.');
        }
    }
}
