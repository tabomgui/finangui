<?php

namespace App\Http\Requests\Transfers;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Só valida que as duas transações existem e são do usuário: qual delas é
 * a saída e qual é a entrada é decidido pela action, pela direção real de
 * cada uma (ver App\Http\Controllers\Api\V1\TransferController::link()) —
 * os nomes dos campos não precisam bater com a direção de verdade.
 */
final class LinkTransferRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $own = Rule::exists('transactions', 'id')->where('user_id', $this->userId());

        return [
            'out_transaction_id' => ['required', 'integer', $own],
            'in_transaction_id' => ['required', 'integer', 'different:out_transaction_id', $own],
        ];
    }
}
