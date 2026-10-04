<?php

namespace App\Http\Requests\Imports;

use App\Http\Requests\ApiRequest;

final class IndexImportBatchesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'integer'],
        ];
    }
}
