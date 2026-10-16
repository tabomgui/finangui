<?php

namespace App\Http\Resources;

use App\Domain\Categorization\Data\PendingCategorizationResult;
use App\Domain\Categorization\Data\PendingGroup;
use App\Domain\Categorization\Data\PendingTransactionItem;
use App\Domain\Transactions\Enums\Direction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o resultado já pronto de
 * App\Domain\Categorization\Queries\PendingCategorization::pending().
 *
 * @mixin PendingCategorizationResult
 */
final class PendingCategorizationResource extends JsonResource
{
    /**
     * @return array{
     *     total_pending: int,
     *     group_count: int,
     *     truncated?: true,
     *     groups: list<array{
     *         key: string,
     *         name: string,
     *         out_count: int,
     *         in_count: int,
     *         net: int,
     *         suggestion?: array{category_id: int},
     *         transactions: list<array{
     *             id: int,
     *             date: string,
     *             description: string,
     *             original_description: string,
     *             amount: int,
     *             direction: Direction,
     *             account_id: int,
     *             account_name: string,
     *         }>,
     *     }>,
     * }
     */
    public function toArray(Request $request): array
    {
        $groups = [];
        foreach ($this->groups as $group) {
            $groups[] = $this->groupToArray($group);
        }

        return [
            'total_pending' => $this->totalPending,
            'group_count' => $this->groupCount,
            'truncated' => $this->when($this->truncated, fn () => true),
            'groups' => $groups,
        ];
    }

    /**
     * suggestion some quando o grupo não tem uma sugestão confiável pelo
     * histórico — ver App\Domain\Categorization\Data\PendingGroup.
     *
     * @return array{
     *     key: string,
     *     name: string,
     *     out_count: int,
     *     in_count: int,
     *     net: int,
     *     suggestion?: array{category_id: int},
     *     transactions: list<array{
     *         id: int,
     *         date: string,
     *         description: string,
     *         original_description: string,
     *         amount: int,
     *         direction: Direction,
     *         account_id: int,
     *         account_name: string,
     *     }>,
     * }
     */
    private function groupToArray(PendingGroup $group): array
    {
        $transactions = [];
        foreach ($group->transactions as $item) {
            $transactions[] = $this->transactionToArray($item);
        }

        return [
            'key' => $group->key,
            'name' => $group->name,
            'out_count' => $group->outCount,
            'in_count' => $group->inCount,
            'net' => $group->net,
            'suggestion' => $this->when($group->suggestionCategoryId !== null, fn () => [
                'category_id' => (int) $group->suggestionCategoryId,
            ]),
            'transactions' => $transactions,
        ];
    }

    /**
     * original_description (texto do banco) é o campo que a regra padrão do
     * frontend casa: a tela conta localmente quantos pendentes uma regra
     * casaria, sem chamar /rules/preview.
     *
     * @return array{
     *     id: int,
     *     date: string,
     *     description: string,
     *     original_description: string,
     *     amount: int,
     *     direction: Direction,
     *     account_id: int,
     *     account_name: string,
     * }
     */
    private function transactionToArray(PendingTransactionItem $item): array
    {
        return [
            'id' => $item->id,
            'date' => $item->date,
            'description' => $item->description,
            'original_description' => $item->originalDescription,
            'amount' => $item->amount,
            'direction' => $item->direction,
            'account_id' => $item->accountId,
            'account_name' => $item->accountName,
        ];
    }
}
