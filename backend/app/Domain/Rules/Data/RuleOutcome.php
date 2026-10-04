<?php

namespace App\Domain\Rules\Data;

/**
 * O que as regras querem mudar numa transação. Campos nulos: nada a mudar.
 */
final class RuleOutcome
{
    public ?int $categoryId = null;

    public ?int $categoryRuleId = null;

    public ?string $description = null;

    public ?string $payee = null;

    /** @var list<int> */
    public array $tagIds = [];

    public bool $ignore = false;

    /** @var list<int|null> */
    public array $matchedRuleIds = [];

    public function matched(): bool
    {
        return $this->matchedRuleIds !== [];
    }
}
