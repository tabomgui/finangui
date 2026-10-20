<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\RulePreviewResult;
use App\Domain\Rules\Data\RulePreviewSample;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A amostra traz as transações mais recentes que casam, mudando ou não
 * ("would_change"). "changes" sempre sai com as 5 chaves (nulo, lista vazia ou false quando
 * aquela mudança não se aplica), para o tipo no frontend ficar estável, sem
 * variar com o que a regra decide mudar.
 *
 * @mixin RulePreviewResult
 */
final class RulePreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'matched' => $this->matched,
            'changed' => $this->changed,
            'uncategorized' => $this->uncategorized,
            'other_category' => $this->otherCategory,
            'already_in_category' => $this->alreadyInCategory,
            'sample' => Collection::make($this->sample)->map(fn (RulePreviewSample $item) => [
                'transaction' => TransactionResource::make($item->transaction),
                'would_change' => $item->wouldChange,
                'changes' => [
                    'category_id' => $item->categoryId,
                    'description' => $item->description,
                    'payee' => $item->payee,
                    'tag_ids' => $item->tagIds,
                    'is_ignored' => $item->isIgnored,
                ],
            ])->all(),
        ];
    }
}
