<?php

namespace App\Http\Requests\Rules;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\ValidatesRuleDefinition;

// Mesmo corpo de StoreRuleRequest sem "name" (a regra ainda não existe),
// mais "overwrite" opcional, com a mesma semântica da aplicação retroativa.
final class PreviewRuleRequest extends ApiRequest
{
    use ValidatesRuleDefinition;

    /**
     * O corpo chega em JSON (não em query string), mas normaliza do mesmo
     * jeito: aceitar "true"/"false" como string também, sem abrir mão da
     * regra "boolean" usada pelo Scramble para documentar o parâmetro.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('overwrite')) {
            $this->merge([
                'overwrite' => filter_var($this->input('overwrite'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match' => ['required', 'in:all,any'],
            'conditions' => ['required', 'array', 'min:1'],
            'actions' => ['required', 'array', 'min:1'],
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
