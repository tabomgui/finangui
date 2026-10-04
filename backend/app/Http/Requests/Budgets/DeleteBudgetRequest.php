<?php

namespace App\Http\Requests\Budgets;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class DeleteBudgetRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'month' => ['sometimes', 'date_format:Y-m'],
        ];
    }
}
