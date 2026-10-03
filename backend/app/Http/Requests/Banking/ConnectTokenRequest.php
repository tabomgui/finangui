<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\ApiRequest;

final class ConnectTokenRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Sem Rule::exists aqui de propósito: queremos 404 (não 422) para
        // uma connection_id de outro usuário — o controller resolve via
        // BankConnection::findOrFail(), que já é escopado por BelongsToUser.
        return [
            'connection_id' => ['sometimes', 'integer'],
        ];
    }
}
