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
            'opening_balance' => ['sometimes', 'integer'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
        ];
    }
}
