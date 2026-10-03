<?php

namespace App\Http\Requests\Imports;

use App\Http\Requests\ApiRequest;

final class ConfirmImportBatchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skip_lines' => ['sometimes', 'array'],
            'skip_lines.*' => ['integer', 'min:1'],
        ];
    }
}
