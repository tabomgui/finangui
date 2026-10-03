<?php

namespace App\Domain\Rules\Data;

use App\Domain\Rules\Models\Rule;

/**
 * Regra na forma que o motor avalia: id (nulo na prévia, regra ainda não
 * salva), match do nível raiz, condições e ações já em array (JSON decodado).
 */
final readonly class RuleDefinition
{
    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     */
    public function __construct(
        public ?int $id,
        public string $match,
        public array $conditions,
        public array $actions,
    ) {}

    public static function fromRule(Rule $rule): self
    {
        // @phpstan-ignore arrayValues.list, arrayValues.list (defesa contra índices não sequenciais no JSON decodado; o docblock do model é só uma declaração nossa, não uma garantia)
        return new self($rule->id, $rule->match, array_values($rule->conditions), array_values($rule->actions));
    }

    /**
     * @param  array<string, mixed>  $input  dados já validados
     */
    public static function fromInput(array $input): self
    {
        return new self(null, (string) $input['match'], array_values($input['conditions']), array_values($input['actions']));
    }
}
