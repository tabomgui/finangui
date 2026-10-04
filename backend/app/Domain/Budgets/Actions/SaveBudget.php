<?php

namespace App\Domain\Budgets\Actions;

use App\Domain\Budgets\Models\Budget;
use Carbon\CarbonImmutable;

/**
 * Upsert do orçamento de uma categoria: sem month, grava/atualiza o padrão
 * mensal; com month, grava/atualiza a exceção daquele mês.
 */
final class SaveBudget
{
    /**
     * @param  array{category_id: int, amount: int, month?: string|null}  $input  dados já validados
     */
    public function handle(array $input): Budget
    {
        $month = array_key_exists('month', $input) && $input['month'] !== null
            ? CarbonImmutable::createFromFormat('!Y-m', $input['month'])->toDateString()
            : null;

        return Budget::query()->updateOrCreate(
            ['category_id' => $input['category_id'], 'month' => $month],
            ['amount' => $input['amount']],
        );
    }
}
