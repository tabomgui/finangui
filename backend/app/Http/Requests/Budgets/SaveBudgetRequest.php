<?php

namespace App\Http\Requests\Budgets;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Budgets\Concerns\ValidatesBudgetableCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveBudgetRequest extends ApiRequest
{
    use ValidatesBudgetableCategory;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            // Ausente = padrão mensal; quando informado, é a exceção daquele mês.
            'month' => ['sometimes', 'date_format:Y-m'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                $this->validateBudgetableCategory($validator);
            }
        }];
    }
}
