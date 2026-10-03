<?php

namespace App\Http\Requests\Rules;

use App\Domain\Rules\Models\Rule;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\ValidatesRuleDefinition;

// Atualização parcial (PATCH): cada campo é `sometimes`. Quando
// conditions/actions/match não vêm, definitionInput() usa o valor atual da
// regra — PATCH actions sem mandar conditions valida o conjunto (atual +
// nova ação), por exemplo.
final class UpdateRuleRequest extends ApiRequest
{
    use ValidatesRuleDefinition;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
            'match' => ['sometimes', 'required', 'in:all,any'],
            'conditions' => ['sometimes', 'required', 'array', 'min:1'],
            'actions' => ['sometimes', 'required', 'array', 'min:1'],
        ];
    }

    protected function shouldValidateDefinition(): bool
    {
        return $this->hasAny(['match', 'conditions', 'actions']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function definitionInput(): array
    {
        $rule = $this->rule();

        return [
            'match' => $this->input('match', $rule->match),
            'conditions' => $this->has('conditions') ? $this->input('conditions') : $rule->conditions,
            'actions' => $this->has('actions') ? $this->input('actions') : $rule->actions,
        ];
    }

    private function rule(): Rule
    {
        $rule = $this->route('rule');
        assert($rule instanceof Rule);

        return $rule;
    }
}
