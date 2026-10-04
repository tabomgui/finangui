<?php

namespace App\Http\Requests\Rules;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\NormalizesOverwrite;

// Corpo de POST /rules/{rule}/apply: só "overwrite" opcional.
final class ApplyRuleRequest extends ApiRequest
{
    use NormalizesOverwrite;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
