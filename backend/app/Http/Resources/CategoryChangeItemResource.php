<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\CategoryChangeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma linha da reconciliação de regra ou da revisão de regras.
 * current_category_id, new_category_id e rule_id/rule_name somem quando
 * não existem (sem categoria atual; a mudança tira a categoria; sem regra
 * para mostrar — excluída).
 *
 * @mixin CategoryChangeItem
 */
final class CategoryChangeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_id' => $this->transactionId,
            'date' => $this->date,
            'description' => $this->description,
            'account_name' => $this->accountName,
            'amount' => $this->amount,
            'direction' => $this->direction,
            'current_category_id' => $this->when($this->currentCategoryId !== null, fn () => (int) $this->currentCategoryId),
            'new_category_id' => $this->when($this->newCategoryId !== null, fn () => (int) $this->newCategoryId),
            'rule_id' => $this->when($this->ruleId !== null, fn () => (int) $this->ruleId),
            'rule_name' => $this->when($this->ruleName !== null, fn () => (string) $this->ruleName),
        ];
    }
}
