<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Data\RuleReviewApplyResult;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aplica a revisão de regras (App\Domain\Rules\Queries\ReviewRules) aos
 * lançamentos escolhidos, síncrono e numa única transação. Nunca confia no
 * que a tela mostrou: cada id é travado (FOR UPDATE, por id) e recalculado
 * com as regras ativas de agora; só muda quem ainda está em fill, conflict
 * ou stale (à mão nunca). Os demais — fora do escopo, de outro usuário, ou
 * que mudaram desde a prévia a ponto de não terem mais o que mudar — contam
 * em "skipped".
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
     * @param  list<int>  $transactionIds  no máximo CategoryChangePlanner::MAX_CHANGES (validado no request)
     */
    public function handle(int $userId, array $transactionIds): RuleReviewApplyResult
    {
        return DB::transaction(function () use ($userId, $transactionIds): RuleReviewApplyResult {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            $rules = $this->planner->activeRules();
            $ids = array_values(array_unique($transactionIds));
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

                    if ($change !== null && $change->group->applicable() && $this->planner->apply($change)) {
                        $applied++;
                    }
                }
            }

            return new RuleReviewApplyResult(applied: $applied, skipped: count($ids) - $applied);
        });
    }
}
