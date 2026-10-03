<?php

namespace App\Http\Requests\Rules;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\DocumentsConditionsAndActions;
use App\Http\Requests\Rules\Concerns\ValidatesRuleDefinition;

// Corpo de uma regra nova: o shape detalhado de conditions/actions é
// responsabilidade do RuleDefinitionValidator, chamado pelo trait abaixo.
final class StoreRuleRequest extends ApiRequest
{
    use DocumentsConditionsAndActions;
    use ValidatesRuleDefinition;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'match' => ['required', 'in:all,any'],
            'conditions' => ['required', 'array', 'min:1'],
            ...self::conditionItemRules('conditions.*'),
            'actions' => ['required', 'array', 'min:1'],
            ...self::actionItemRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function definitionInput(): array
    {
        return [
            'match' => $this->input('match'),
            'conditions' => $this->input('conditions'),
            'actions' => $this->input('actions'),
        ];
    }
}
