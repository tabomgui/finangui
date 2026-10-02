<?php

namespace App\Http\Requests\Transactions;

use App\Domain\Transactions\Enums\Direction;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class StoreTransactionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            'direction' => ['required', Rule::enum(Direction::class)],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payee' => ['nullable', 'string', 'max:120'],
            'is_ignored' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('user_id', $this->userId())],
            // Sem `nullable`: enviar statement_id como null é rejeitado de propósito.
            // A escolha é automática quando o campo simplesmente não é enviado.
            'statement_id' => ['sometimes', 'integer', Rule::exists('card_statements', 'id')->where('user_id', $this->userId())],
            'installments' => ['sometimes', 'integer', 'between:1,48'],
        ];
    }
}
