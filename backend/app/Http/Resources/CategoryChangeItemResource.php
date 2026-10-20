<?php

namespace App\Http\Resources;

use App\Domain\Rules\Data\CategoryChangeItem;
use App\Domain\Rules\Enums\CategorySource;
use App\Domain\Transactions\Enums\Direction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma linha da reconciliação de regra ou da revisão de regras.
 * current_category_id, new_category_id e rule_id/rule_name/rule_active
 * somem quando não existem (sem categoria atual; a mudança tira a
 * categoria; sem regra para mostrar — excluída). current_source diz quem
 * deu a categoria atual (some sem categoria ou com origem desconhecida);
 * current_rule_name, o nome da regra que deu, quando ela ainda existe.
 *
 * @mixin CategoryChangeItem
 */
final class CategoryChangeItemResource extends JsonResource
{
    /**
     * @return array{
     *     transaction_id: int,
     *     date: string,
     *     description: string,
     *     account_name: string,
     *     amount: int,
     *     direction: Direction,
     *     current_category_id?: int,
     *     new_category_id?: int,
     *     rule_id?: int,
     *     rule_name?: string,
     *     rule_active?: bool,
     *     current_source?: CategorySource,
     *     current_rule_name?: string,
     * }
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
            'rule_active' => $this->when($this->ruleActive !== null, fn (): bool => (bool) $this->ruleActive),
            'current_source' => $this->when($this->currentSource !== null, fn (): CategorySource => $this->knownCurrentSource()),
            'current_rule_name' => $this->when($this->currentRuleName !== null, fn () => (string) $this->currentRuleName),
        ];
    }

    /**
     * Só chamada quando currentSource não é nulo: o tipo de retorno (sem
     * "| null") é o que o Scramble usa para documentar a chave.
     */
    private function knownCurrentSource(): CategorySource
    {
        /** @var CategorySource $source */
        $source = $this->currentSource;

        return $source;
    }
}
