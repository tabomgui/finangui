<?php

namespace App\Http\Requests\Rules;

use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Http\Requests\ApiRequest;

final class ApplyRuleReviewRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Lançamentos escolhidos na revisão (GET /rules/review); cada um é recalculado antes de mudar.
            'transaction_ids' => ['required', 'array', 'min:1', 'max:'.CategoryChangePlanner::MAX_CHANGES],
            'transaction_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function transactionIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('transaction_ids');

        return array_map(intval(...), $ids);
    }
}
