<?php

namespace App\Http\Requests\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class StoreAccountRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'opening_balance' => ['sometimes', 'integer', 'between:-1000000000000000,1000000000000000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'credit_limit' => ['required_if:type,credit_card', 'prohibited_unless:type,credit_card', 'nullable', 'integer', 'between:0,1000000000000000'],
            'closing_day' => ['required_if:type,credit_card', 'prohibited_unless:type,credit_card', 'nullable', 'integer', 'between:1,31'],
            'due_day' => ['required_if:type,credit_card', 'prohibited_unless:type,credit_card', 'nullable', 'integer', 'between:1,31'],
            'last_four' => ['prohibited_unless:type,credit_card', 'nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
