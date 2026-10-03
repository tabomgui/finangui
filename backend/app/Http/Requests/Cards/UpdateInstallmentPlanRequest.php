<?php

namespace App\Http\Requests\Cards;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class UpdateInstallmentPlanRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
        ];
    }
}
