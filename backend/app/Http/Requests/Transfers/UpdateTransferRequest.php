<?php

namespace App\Http\Requests\Transfers;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class UpdateTransferRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ownAccount = Rule::exists('accounts', 'id')->where('user_id', $this->userId());

        return [
            'from_account_id' => ['sometimes', 'integer', $ownAccount],
            'to_account_id' => ['sometimes', 'integer', $ownAccount],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000000'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
