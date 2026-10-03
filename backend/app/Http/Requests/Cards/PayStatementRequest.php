<?php

namespace App\Http\Requests\Cards;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class PayStatementRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
