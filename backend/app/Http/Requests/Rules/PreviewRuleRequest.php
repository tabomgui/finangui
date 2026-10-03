<?php

namespace App\Http\Requests\Rules;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\DocumentsConditionsAndActions;
use App\Http\Requests\Rules\Concerns\NormalizesOverwrite;
use App\Http\Requests\Rules\Concerns\ValidatesRuleDefinition;

// Mesmo corpo de StoreRuleRequest sem "name" (a regra ainda não existe),
// mais "overwrite" opcional, com a mesma semântica da aplicação retroativa.
final class PreviewRuleRequest extends ApiRequest
{
    use DocumentsConditionsAndActions;
    use NormalizesOverwrite;
    use ValidatesRuleDefinition;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match' => ['required', 'in:all,any'],
            'conditions' => ['required', 'array', 'min:1'],
            ...self::conditionItemRules('conditions.*'),
            'actions' => ['required', 'array', 'min:1'],
            ...self::actionItemRules(),
            'overwrite' => ['sometimes', 'boolean'],
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
