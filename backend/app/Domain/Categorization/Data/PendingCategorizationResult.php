<?php

namespace App\Domain\Categorization\Data;

/**
 * Resultado de App\Domain\Categorization\Queries\PendingCategorization::pending():
 * os lançamentos pendentes do usuário agrupados por contraparte, ordenados
 * por quantidade de lançamentos (decrescente). truncated só é true quando o
 * total pendente passa do limite e a lista trazida é só a parte mais
 * recente — ver a classe da query.
 */
final readonly class PendingCategorizationResult
{
    /**
     * @param  list<PendingGroup>  $groups
     */
    public function __construct(
        public int $totalPending,
        public int $groupCount,
        public bool $truncated,
        public array $groups,
    ) {}
}
