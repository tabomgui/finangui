<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Data\RuleReviewApplyResult;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aplica a revisão de regras (App\Domain\Rules\Queries\ReviewRules) às
 * linhas escolhidas, síncrono e numa única transação. Nunca confia no que a
 * tela mostrou: cada lançamento é travado (FOR UPDATE, por id) e recalculado
 * com as regras ativas de agora, e só muda se ainda estiver em fill,
 * conflict ou stale (à mão nunca) com o mesmo destino que o usuário viu — a
 * mesma categoria nova, ou tirar a categoria. Os demais (fora do escopo, de
 * outro usuário, ou que mudaram desde a prévia — inclusive porque uma regra
 * mudou e agora daria outra categoria) contam em "skipped".
 *
 * Trava a linha do usuário antes, como
 * App\Domain\Categorization\Actions\ApplyCategorization.
 */
final class ApplyRuleReview
{
    public function __construct(
        private readonly CategoryChangePlanner $planner,
    ) {}

    /**
     * @param  array<int, int|null>  $expected  id do lançamento → categoria nova que a tela mostrou (nula: tirar a categoria); no máximo CategoryChangePlanner::MAX_CHANGES (validado no request)
     */
    public function handle(int $userId, array $expected): RuleReviewApplyResult
    {
        return DB::transaction(function () use ($userId, $expected): RuleReviewApplyResult {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            $rules = $this->planner->activeRules();
            $ids = array_keys($expected);
            sort($ids);
            $applied = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $transactions = Transaction::query()
                    ->eligibleForRules()
                    ->whereIn('id', $chunk)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($transactions as $transaction) {
                    $change = $this->planner->forRules($transaction, $rules);

                    if ($change !== null
                        && $change->group->applicable()
                        && $change->categoryId === $expected[$transaction->id]
                        && $this->planner->apply($change)) {
                        $applied++;
                    }
                }
            }

            return new RuleReviewApplyResult(applied: $applied, skipped: count($ids) - $applied);
        });
    }
}
