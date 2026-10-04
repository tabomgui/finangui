<?php

namespace App\Http\Requests\Transfers;

use App\Http\Requests\ApiRequest;

final class IndexTransferSuggestionsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
