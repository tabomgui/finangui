<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Queries\PreviewRule::handle(): quantas
 * transações do usuário casam com a regra e quantas de fato mudariam, mais
 * uma amostra de até 20, mais recentes primeiro.
 */
final readonly class RulePreviewResult
{
    /**
     * @param  list<RulePreviewSample>  $sample
     */
    public function __construct(
        public int $matched,
        public int $changed,
        public array $sample,
    ) {}
}
