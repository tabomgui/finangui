<?php

namespace App\Http\Requests\Transactions;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class IndexTransactionsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            // category_exact restringe category_id à própria categoria, sem
            // incluir as subcategorias (comportamento padrão de category_id).
            'category_exact' => ['sometimes', 'boolean'],
            // no_category filtra só lançamentos sem categoria nenhuma.
            'no_category' => ['sometimes', 'boolean'],
            'statement_id' => ['sometimes', 'integer'],
            'tag_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::enum(TransactionStatus::class)],
            'direction' => ['sometimes', Rule::enum(Direction::class)],
            // reportable aplica Transaction::scopeReportable() (exclui
            // transferências, ignorados e categorias de transferência), a
            // mesma base usada pelos relatórios de despesa.
            'reportable' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    /**
     * O openapi-fetch do frontend serializa boolean como a string "true"/
     * "false" na query string; a regra `boolean` só aceita 0/1/"0"/"1"/true/
     * false literais. Normaliza antes de validar, sem abrir mão da regra (que
     * o Scramble usa para documentar o parâmetro como boolean) — mesmo
     * padrão de App\Http\Requests\Cards\IndexCardsRequest.
     */
    protected function prepareForValidation(): void
    {
        foreach (['category_exact', 'no_category', 'reportable'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
            }
        }
    }
}
