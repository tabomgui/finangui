<?php

namespace App\Http\Requests\Rules\Concerns;

use App\Domain\Rules\Enums\RuleActionType;
use App\Domain\Rules\Enums\RuleField;
use App\Domain\Rules\Enums\RuleOperator;
use Illuminate\Validation\Rule;

// Compartilhado entre Store/Update/PreviewRuleRequest: regras "sometimes",
// deliberadamente sem a força de validação completa (o shape de fato —
// operador válido para o campo, quantidade máxima, regex, dono de
// categoria/tag/conta etc. — é checado por RuleDefinitionValidator via
// ValidatesRuleDefinition::after(), que roda mesmo quando as regras abaixo
// passam). O propósito aqui é só o Scramble documentar cada item de
// conditions/actions com union literal nos campos que têm um conjunto fixo
// de valores (field/op/match/type), em vez de "string" genérico — por isso
// usamos os mesmos enums do motor, nunca uma lista hardcoded à parte.
// "value" fica sem tipo (pode ser string ou int, dependendo do campo): o
// tipo preciso do corpo da requisição é documentado no frontend
// (`RuleBody` em api/types.ts), não aqui.
trait DocumentsConditionsAndActions
{
    /**
     * @return array<string, mixed>
     */
    protected static function conditionItemRules(string $prefix): array
    {
        $fields = Rule::in(array_map(fn (RuleField $field) => $field->value, RuleField::cases()));
        $operators = Rule::in(array_map(fn (RuleOperator $operator) => $operator->value, RuleOperator::cases()));

        return [
            "{$prefix}.field" => ['sometimes', $fields],
            "{$prefix}.op" => ['sometimes', $operators],
            "{$prefix}.value" => ['sometimes'],
            "{$prefix}.match" => ['sometimes', 'in:all,any'],
            "{$prefix}.conditions" => ['sometimes', 'array'],
            // Grupos têm só um nível: as condições de dentro de um grupo não
            // precisam do mesmo detalhe que as de fora, mas documentar field/op
            // já tira o "string[][]" (lista de arrays de string) que o Scramble
            // produziria sem nenhuma chave aqui.
            "{$prefix}.conditions.*.field" => ['sometimes', $fields],
            "{$prefix}.conditions.*.op" => ['sometimes', $operators],
            "{$prefix}.conditions.*.value" => ['sometimes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function actionItemRules(string $prefix = 'actions.*'): array
    {
        return [
            "{$prefix}.type" => ['sometimes', Rule::in(array_map(fn (RuleActionType $type) => $type->value, RuleActionType::cases()))],
            "{$prefix}.category_id" => ['sometimes', 'integer'],
            "{$prefix}.tag_id" => ['sometimes', 'integer'],
            "{$prefix}.value" => ['sometimes', 'string'],
        ];
    }
}
