<?php

namespace App\Http\Requests\Transfers;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class StoreTransferRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ownAccount = Rule::exists('accounts', 'id')->where('user_id', $this->userId());

        return [
            'from_account_id' => ['required', 'integer', $ownAccount],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', $ownAccount],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
