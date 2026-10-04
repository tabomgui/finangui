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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aplica retroativamente uma regra já salva a todas as transações do
 * usuário que casam (pernas de transferência ficam de fora), com
 * "overwrite" opcional. Roda em UserContext para o escopo por usuário
 * valer; grava last_applied_at/last_applied_changes na regra ao terminar.
 *
 * Único por regra (ShouldBeUnique): aplicar duas vezes em paralelo não
 * corrompe nada (idempotente), mas desperdiça trabalho e pode fazer
 * last_applied_changes gravado por último não refletir o scan mais recente.
 * $timeout/$tries: scan pode varrer muitas transações; melhor deixar rodar
 * (até 10 minutos, abaixo do DB_QUEUE_RETRY_AFTER) do que matar e tentar de
 * novo no meio.
 */
final class ApplyRuleRetroactively implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $ruleId,
        public int $userId,
        public bool $overwrite = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->ruleId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

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

            // Carregar tags custa uma query a mais por lote; só compensa
            // quando a regra tem add_tag (as outras ações nunca tocam
            // $transaction->tags).
            $query = Transaction::query()->whereNull('transfer_id');
            if ($definition->usesAddTag()) {
                $query->with('tags');
            }

            $query->lazyById(500)
                ->each(function (Transaction $transaction) use ($definition, $apply, &$changed): void {
                    $context = RuleContext::forExisting($transaction, $this->overwrite);
                    $outcome = RuleEngine::evaluate(RuleSubject::fromTransaction($transaction), [$definition], $context);

                    if ($apply->handle($transaction, $outcome)) {
                        $changed++;
                    }
                });

            $rule->update(['last_applied_at' => now(), 'last_applied_changes' => $changed]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Falha ao aplicar regra retroativamente.', [
            'rule_id' => $this->ruleId,
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
