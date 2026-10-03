<?php

namespace App\Http\Requests\Rules\Concerns;

// Compartilhado entre Store/Update/PreviewRuleRequest: regras "sometimes"
// sem força de validação (o shape de fato — campos válidos por tipo,
// quantidade máxima, regex etc. — é checado por RuleDefinitionValidator via
// ValidatesRuleDefinition::after()). Só para o Scramble documentar cada item
// de conditions/actions como objeto com as chaves possíveis, em vez de um
// array genérico sem tipo.
trait DocumentsConditionsAndActions
{
    /**
     * @return array<string, mixed>
     */
    protected static function conditionItemRules(string $prefix): array
    {
        return [
            "{$prefix}.field" => ['sometimes', 'string'],
            "{$prefix}.op" => ['sometimes', 'string'],
            "{$prefix}.value" => ['sometimes'],
            "{$prefix}.match" => ['sometimes', 'string'],
            "{$prefix}.conditions" => ['sometimes', 'array'],
            // Grupos têm só um nível: as condições de dentro de um grupo não
            // precisam do mesmo detalhe, "array" já ajuda o bastante aqui.
            "{$prefix}.conditions.*" => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function actionItemRules(string $prefix = 'actions.*'): array
    {
        return [
            "{$prefix}.type" => ['sometimes', 'string'],
            "{$prefix}.category_id" => ['sometimes', 'integer'],
            "{$prefix}.tag_id" => ['sometimes', 'integer'],
            "{$prefix}.value" => ['sometimes', 'string'],
        ];
    }
}
