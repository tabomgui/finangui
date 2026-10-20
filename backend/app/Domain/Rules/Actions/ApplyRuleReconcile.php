<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleReconcileApplyResult;
use App\Domain\Rules\Enums\ReconcileGroup;
use App\Domain\Rules\Errors\TooManyCategoryChanges;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aplica os grupos escolhidos da reconciliação de uma regra editada (ver
 * App\Domain\Rules\Queries\ReconcileRule), síncrono e numa única transação:
 * acima de CategoryChangePlanner::MAX_CHANGES, nada é gravado
 * (TooManyCategoryChanges).
 *
 * Trava a linha do usuário (como App\Domain\Categorization\Actions\ApplyCategorization,
 * serializando com ela e com a importação) e a da regra, relida dentro da
 * transação: vale a definição salva agora, não a da prévia. Os lançamentos
 * de cada grupo são recalculados na hora; os escolhidos são travados (FOR
 * UPDATE, por id) e recalculados de novo antes de gravar — quem saiu do
 * grupo entre uma coisa e outra só conta em "skipped".
 */
final class ApplyRuleReconcile
{
    public function __construct(
        private readonly CategoryChangePlanner $planner,
    ) {}

    /**
     * @param  list<ReconcileGroup>  $groups
     *
     * @throws TooManyCategoryChanges
     */
    public function handle(Rule $rule, array $groups): RuleReconcileApplyResult
    {
        return DB::transaction(function () use ($rule, $groups): RuleReconcileApplyResult {
            User::query()->whereKey($rule->user_id)->lockForUpdate()->first();

            $locked = Rule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $definition = RuleDefinition::fromRule($locked);

            $ids = [];
            $this->planner->reconcileCandidates($locked->id)
                ->lazyById(500)
                ->each(function (Transaction $transaction) use ($definition, $locked, $groups, &$ids): void {
                    $change = $this->planner->forRule($transaction, $definition, $locked->is_active);

                    if ($change !== null && in_array($change->group, $groups, true)) {
                        $ids[] = $transaction->id;
                    }
                });

            if (count($ids) > CategoryChangePlanner::MAX_CHANGES) {
                throw new TooManyCategoryChanges(count($ids));
            }

            /** @var array<string, int> $applied */
            $applied = array_fill_keys(array_map(fn (ReconcileGroup $g) => $g->value, ReconcileGroup::cases()), 0);
            $skipped = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $transactions = Transaction::query()
                    ->eligibleForRules()
                    ->whereIn('id', $chunk)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $skipped += count($chunk) - $transactions->count();

                foreach ($transactions as $transaction) {
                    $change = $this->planner->forRule($transaction, $definition, $locked->is_active);

                    if ($change !== null && in_array($change->group, $groups, true) && $this->planner->apply($change)) {
                        $applied[$change->group->value]++;
                    } else {
                        $skipped++;
                    }
                }
            }

            return new RuleReconcileApplyResult(
                recategorize: $applied[ReconcileGroup::Recategorize->value],
                fill: $applied[ReconcileGroup::Fill->value],
                clear: $applied[ReconcileGroup::Clear->value],
                skipped: $skipped,
            );
        });
    }
}
