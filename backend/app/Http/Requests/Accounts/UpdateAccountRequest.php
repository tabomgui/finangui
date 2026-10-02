<?php

namespace App\Http\Requests\Accounts;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
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
        // Scramble chama rules() fora de uma request real (sem rota) para gerar a
        // doc da API: $account precisa ser null-safe, e as regras de tipo (integer/
        // string) precisam aparecer sempre, sem depender de closures condicionais,
        // para o schema gerado não cair em "string" por falta de pista.
        $account = $this->route('account');
        $current = $account instanceof Account ? $account->type->value : null;
        $isCard = $this->input('type', $current) === AccountType::CreditCard->value;
        $becomingCard = $isCard && $current !== AccountType::CreditCard->value;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'opening_balance' => ['sometimes', 'integer', 'between:-1000000000000000,1000000000000000'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_archived' => ['sometimes', 'boolean'],
            'credit_limit' => [Rule::requiredIf($becomingCard), Rule::prohibitedIf(! $isCard), 'integer', 'between:0,1000000000000000'],
            'closing_day' => [Rule::requiredIf($becomingCard), Rule::prohibitedIf(! $isCard), 'integer', 'between:1,31'],
            'due_day' => [Rule::requiredIf($becomingCard), Rule::prohibitedIf(! $isCard), 'integer', 'between:1,31'],
            'last_four' => [Rule::prohibitedIf(! $isCard), 'nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
