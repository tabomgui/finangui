<?php

namespace App\Http\Requests\Cards;

use App\Http\Requests\ApiRequest;

final class IndexCardsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * O openapi-fetch do frontend serializa boolean como a string "true"/
     * "false" na query string; a regra `boolean` só aceita 0/1/"0"/"1"/true/
     * false literais. Normaliza antes de validar, sem abrir mão da regra (que
     * o Scramble usa para documentar o parâmetro como boolean).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('include_archived')) {
            $this->merge([
                'include_archived' => filter_var($this->input('include_archived'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
