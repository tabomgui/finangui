<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\ApiRequest;

/**
 * `item_id` é o item devolvido pelo widget ao fim do fluxo de reconexão (modo atualização);
 * o controller confere contra `connection->external_id` antes de marcar como reconectada —
 * ver App\Http\Controllers\Api\V1\BankConnectionController::reconnected().
 */
final class ReconnectedRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'string'],
        ];
    }
}
