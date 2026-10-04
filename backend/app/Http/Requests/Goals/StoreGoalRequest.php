<?php

namespace App\Http\Requests\Goals;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class StoreGoalRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'target_amount' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            'target_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
        ];
    }
}
