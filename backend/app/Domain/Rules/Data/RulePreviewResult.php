<?php

namespace App\Domain\Rules\Data;

/**
 * Resultado de App\Domain\Rules\Queries\PreviewRule::handle(): quantas
 * transações do usuário casam com a regra e quantas de fato mudariam, mais
 * uma amostra de até 20, mais recentes primeiro. uncategorized/otherCategory
 * dividem os que casam pela categoria atual, sem depender de "overwrite":
 * sem categoria que receberiam a da regra, e com outra categoria (inclusive
 * à mão).
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
        public array $sample,
    ) {}
}
