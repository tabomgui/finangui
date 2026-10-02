<?php

namespace App\Http\Requests\Cards;

use App\Http\Requests\ApiRequest;

final class IndexCardsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'include_archived' => ['sometimes', 'boolean'],
        ];
    }
}
