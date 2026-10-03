<?php

namespace App\Http\Requests\Cards;

use App\Http\Requests\ApiRequest;

final class StatementPreviewRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
