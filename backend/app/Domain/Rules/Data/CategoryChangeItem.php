<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Enums\CategorySource;
use App\Domain\Transactions\Enums\Direction;

/**
 * Uma linha exibida da reconciliação ou da revisão de regras. Nulos (sem
 * categoria atual, sem categoria nova, sem regra, origem desconhecida) saem
 * omitidos da resposta. currentSource/currentRuleName: quem deu a categoria
 * atual (e o nome da regra, quando foi uma que ainda existe); ruleActive
 * acompanha ruleId.
 */
final readonly class CategoryChangeItem
{
    public function __construct(
        public int $transactionId,
        public string $date,
        public string $description,
        public string $accountName,
        public int $amount,
        public Direction $direction,
        public ?int $currentCategoryId,
        public ?int $newCategoryId,
        public ?int $ruleId,
        public ?string $ruleName,
        public ?bool $ruleActive = null,
        public ?CategorySource $currentSource = null,
        public ?string $currentRuleName = null,
    ) {}
}
