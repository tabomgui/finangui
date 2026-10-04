<?php

namespace App\Http\Requests\Recurrences;

use App\Http\Requests\ApiRequest;

final class ConfirmOccurrenceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000000'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
