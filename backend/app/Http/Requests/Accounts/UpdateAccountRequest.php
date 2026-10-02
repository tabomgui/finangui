<?php

namespace App\Http\Requests\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * `currency` não é editável: mudar a moeda de uma conta com histórico
 * corromperia os valores. Campos fora das regras são ignorados.
 */
final class UpdateAccountRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'opening_balance' => ['sometimes', 'integer', 'between:-1000000000000000,1000000000000000'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }
}
