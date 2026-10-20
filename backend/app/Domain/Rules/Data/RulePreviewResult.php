<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Queries\PreviewRule::handle(): quantas
 * transações do usuário casam com a regra e quantas de fato mudariam, mais
 * uma amostra das até 20 mais recentes que casam. uncategorized,
 * otherCategory e alreadyInCategory dividem os que casam pela categoria
 * atual, sem depender de "overwrite": sem categoria que receberiam a da
 * regra, com outra categoria (inclusive à mão) e já na categoria da regra.
 */
final readonly class RulePreviewResult
{
    /**
     * @param  list<RulePreviewSample>  $sample
     */
    public function __construct(
        public int $matched,
        public int $changed,
        public int $uncategorized,
        public int $otherCategory,
        public int $alreadyInCategory,
        public array $sample,
    ) {}
}
