<?php

namespace App\Http\Requests\Transactions;

use App\Domain\Transactions\Enums\Direction;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class UpdateTransactionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000000'],
            'direction' => ['sometimes', Rule::enum(Direction::class)],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'payee' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_ignored' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('user_id', $this->userId())],
            'statement_id' => ['sometimes', 'integer', Rule::exists('card_statements', 'id')->where('user_id', $this->userId())],
        ];
    }
}
