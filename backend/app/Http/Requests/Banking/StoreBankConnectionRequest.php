<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\ApiRequest;

final class StoreBankConnectionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid'],
        ];
    }
}
