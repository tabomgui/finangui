<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateProfileRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasPassword = $this->user()?->getAuthPassword() !== null;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'password' => ['sometimes', 'required', 'confirmed', Password::min(8)],
            'current_password' => [
                Rule::requiredIf(fn () => $hasPassword && $this->filled('password')),
                'nullable',
                'current_password',
            ],
            // Usar a categoria do banco na sincronização quando nem regra nem histórico categorizam.
            'use_provider_categories' => ['sometimes', 'required', 'boolean'],
            // Só junto de use_provider_categories = false: tira também a categoria de todo lançamento categorizado pelo banco.
            'clear_provider_categories' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn () => $this->boolean('clear_provider_categories') && ! $this->turnsProviderCategoriesOff()),
            ],
        ];
    }

    private function turnsProviderCategoriesOff(): bool
    {
        return $this->has('use_provider_categories') && ! $this->boolean('use_provider_categories');
    }
}
