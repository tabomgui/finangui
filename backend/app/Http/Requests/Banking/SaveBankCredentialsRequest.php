<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\ApiRequest;

final class SaveBankCredentialsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:200', 'uuid'],
            'client_secret' => ['required', 'string', 'max:200'],
        ];
    }
}
