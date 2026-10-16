<?php

namespace App\Domain\Rules\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Rules\Enums\RuleField;

/**
 * Confere, dentro de uma lista de condições já com a forma validada
 * (RuleDefinitionValidator), se cada condição account_id aponta para uma
 * conta do usuário autenticado (Account::query() tem o global scope de
 * App\Models\Concerns\BelongsToUser — a própria consulta já escopa por
 * dono). Compartilhado por App\Http\Requests\Rules\Concerns\ValidatesRuleDefinition
 * (/rules, /rules/preview) e App\Http\Requests\Categorization\ApplyCategorizationRequest
 * (regra de POST /categorization/apply, condições sob "rule."), para não
 * duplicar a recursão em grupos nos dois lugares.
 */
final class RuleConditionOwnershipValidator
{
    /**
     * @param  list<mixed>  $conditions
     * @return array<string, string>
     */
    public static function errors(array $conditions, string $path): array
    {
        $errors = [];

        foreach ($conditions as $i => $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $itemPath = "{$path}.{$i}";

            if (array_key_exists('conditions', $condition) && is_array($condition['conditions'])) {
                $errors += self::errors(array_values($condition['conditions']), "{$itemPath}.conditions");

                continue;
            }

            if (($condition['field'] ?? null) !== RuleField::AccountId->value) {
                continue;
            }

            $accountId = $condition['value'] ?? null;

            if (is_int($accountId) && ! Account::query()->whereKey($accountId)->exists()) {
                $errors["{$itemPath}.value"] = 'Conta não encontrada.';
            }
        }

        return $errors;
    }
}
