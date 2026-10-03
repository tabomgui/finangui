<?php

namespace App\Domain\Rules\Jobs;

use App\Domain\Rules\Actions\ApplyRuleOutcome;
use App\Domain\Rules\Data\RuleContext;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\RuleEngine;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Aplica retroativamente uma regra já salva a todas as transações do
 * usuário que casam (pernas de transferência ficam de fora), com
 * "overwrite" opcional. Roda em UserContext para o escopo por usuário
 * valer; grava last_applied_at/last_applied_changes na regra ao terminar.
 */
final class ApplyRuleRetroactively implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $ruleId,
        public int $userId,
        public bool $overwrite = false,
    ) {}

    public function handle(ApplyRuleOutcome $apply): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        UserContext::run($user, function () use ($apply): void {
            $rule = Rule::query()->find($this->ruleId);

            if ($rule === null) {
                // Excluída antes de o job rodar: nada a fazer.
                return;
            }

            $definition = RuleDefinition::fromRule($rule);
            $changed = 0;

            Transaction::query()->whereNull('transfer_id')->with('tags')->lazyById(500)
                ->each(function (Transaction $transaction) use ($definition, $apply, &$changed): void {
                    $context = new RuleContext(
                        hasCategory: $transaction->category_id !== null,
                        categoryManual: $transaction->categorized_by === 'manual',
                        descriptionLocked: $transaction->description_locked,
                        overwrite: $this->overwrite,
                        onlyCategory: false,
                    );

                    $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), [$definition], $context);

                    if ($apply->handle($transaction, $outcome)) {
                        $changed++;
                    }
                });

            $rule->update(['last_applied_at' => now(), 'last_applied_changes' => $changed]);
        });
    }
}
