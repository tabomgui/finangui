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
            // Linhas escolhidas na revisão (GET /rules/review), cada uma com a categoria nova que a tela mostrou.
            'items' => ['required', 'array', 'min:1', 'max:'.CategoryChangePlanner::MAX_CHANGES],
            'items.*' => ['required', 'array'],
            'items.*.transaction_id' => ['required', 'integer', 'distinct'],
            // Categoria nova mostrada para a linha; omita quando a revisão tira a categoria (grupo stale). Se o recálculo der outra coisa, a linha é pulada.
            'items.*.category_id' => ['sometimes', 'integer'],
        ];
    }

    /**
     * Id do lançamento → categoria nova esperada (nula: tirar a categoria).
     *
     * @return array<int, int|null>
     */
    public function expectedTargets(): array
    {
        /** @var list<array{transaction_id: int|string, category_id?: int|string}> $items */
        $items = $this->validated('items');

        $targets = [];
        foreach ($items as $item) {
            $targets[(int) $item['transaction_id']] = isset($item['category_id']) ? (int) $item['category_id'] : null;
        }

        return $targets;
    }
}
