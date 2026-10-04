<?php

namespace App\Domain\Reports\Data;

/**
 * Resultado de App\Domain\Reports\Queries\MonthlyEvolution::for(): receita,
 * despesa e líquido mês a mês no período pedido, na moeda principal — meses
 * sem lançamento entram com zero, nunca ausentes.
 */
final readonly class MonthlyEvolutionResult
{
    /**
     * @param  list<array{month: string, income: int, expense: int, net: int}>  $months
     */
    public function __construct(
        public string $currency,
        public array $months,
    ) {}
}
