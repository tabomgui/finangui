<?php

namespace App\Http\Requests\Notifications;

use App\Http\Requests\ApiRequest;

final class IndexNotificationsRequest extends ApiRequest
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
