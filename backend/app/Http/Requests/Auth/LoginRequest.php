<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiRequest;

final class LoginRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
