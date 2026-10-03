<?php

namespace App\Http\Requests\Rules;

use App\Domain\Rules\Models\Rule;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

// Corpo de PUT /rules/order: {ids: [...]}, com todo o conjunto de regras do
// usuário, sem repetir — o after() confere que o conjunto enviado é
// exatamente o conjunto de ids do usuário autenticado.
final class ReorderRulesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = array_map(intval(...), $this->input('ids', []));
            $existing = Rule::query()->pluck('id')->map(intval(...))->all();

            if (self::sameSet($ids, $existing)) {
                return;
            }

            $validator->errors()->add('ids', 'Envie todas as regras, sem repetir.');
        }];
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
