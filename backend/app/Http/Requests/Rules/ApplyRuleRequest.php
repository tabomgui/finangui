<?php

namespace App\Http\Requests\Rules;

use App\Http\Requests\ApiRequest;

// Corpo de POST /rules/{rule}/apply: só "overwrite" opcional.
final class ApplyRuleRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('overwrite')) {
            $this->merge([
                'overwrite' => filter_var($this->input('overwrite'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

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
