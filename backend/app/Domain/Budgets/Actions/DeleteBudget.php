<?php

namespace App\Domain\Budgets\Actions;

use App\Domain\Budgets\Models\Budget;
use Carbon\CarbonImmutable;

/**
 * Remove o padrão mensal (sem month) ou a exceção de um mês específico.
 * Idempotente: nada para apagar não é um erro.
 */
final class DeleteBudget
{
    /**
     * @param  array{category_id: int, month?: string|null}  $input  dados já validados
     */
    public function handle(array $input): void
    {
        $month = array_key_exists('month', $input) && $input['month'] !== null
            ? CarbonImmutable::createFromFormat('!Y-m', $input['month'])->toDateString()
            : null;

        Budget::query()->where('category_id', $input['category_id'])->where('month', $month)->delete();
    }
}
