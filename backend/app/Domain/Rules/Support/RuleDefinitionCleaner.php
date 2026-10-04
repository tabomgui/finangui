<?php

namespace App\Domain\Rules\Support;

/**
 * Normaliza conditions/actions já validados (RuleDefinitionValidator +
 * checkOwnership do request) antes de gravar: listas reindexadas e só as
 * chaves que o tipo de nó/ação realmente usa, descartando qualquer chave
 * extra que o cliente tenha mandado.
 */
final class RuleDefinitionCleaner
{
    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return list<array<string, mixed>>
     */
    public static function conditions(array $conditions): array
    {
        return array_map(self::node(...), $conditions);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function node(array $node): array
    {
        if (array_key_exists('conditions', $node) && is_array($node['conditions'])) {
            return [
                'match' => $node['match'] ?? 'all',
                'conditions' => self::conditions($node['conditions']),
            ];
        }

        return [
            'field' => $node['field'] ?? null,
            'op' => $node['op'] ?? null,
            'value' => $node['value'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    public static function actions(array $actions): array
    {
        return array_map(self::action(...), $actions);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function action(array $action): array
    {
        $type = $action['type'] ?? null;

        return match ($type) {
            'set_category' => ['type' => $type, 'category_id' => $action['category_id'] ?? null],
            'set_description', 'set_payee' => ['type' => $type, 'value' => $action['value'] ?? null],
            'add_tag' => ['type' => $type, 'tag_id' => $action['tag_id'] ?? null],
            default => ['type' => $type],
        };
    }
}
