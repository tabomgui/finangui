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
            'ids' => ['required', 'array', 'list'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * Confere o conjunto de ids mesmo quando "ids.*" já falhou (ex.: id
     * repetido): assim duplicado/faltante/de outro usuário sempre aparece em
     * "ids", e não só no índice que a regra "distinct" aponta. Só não roda
     * se "ids" em si não for array (lista com objeto em vez de inteiro,
     * por exemplo) — nesse caso intval() em cada item não ajudaria.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $this->input('ids');

            if (! is_array($input)) {
                return;
            }

            $ids = array_map(intval(...), array_values($input));
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
