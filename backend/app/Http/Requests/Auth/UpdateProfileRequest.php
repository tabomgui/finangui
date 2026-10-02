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
        ];
    }
}
