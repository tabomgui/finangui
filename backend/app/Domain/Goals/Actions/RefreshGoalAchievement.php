<?php

namespace App\Domain\Goals\Actions;

use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Support\GoalProgress;
use Carbon\CarbonImmutable;

/**
 * Grava achieved_at na primeira vez que o progresso de uma meta atinge o
 * alvo. Idempotente e nunca limpa um achieved_at já gravado — uma meta
 * atingida continua atingida, mesmo que o progresso caia depois (saque,
 * saldo da conta baixando etc.).
 *
 * Não há job nem evento dedicado para isso: achieved_at é recalculado aqui,
 * chamada depois de criar/editar a meta e depois de criar um aporte, e de
 * novo ao listar/exibir (GoalController) — cobrindo os pontos em que o
 * progresso pode ter mudado, sem depender de um agendador só para isso.
 */
final class RefreshGoalAchievement
{
    public function handle(Goal $goal): Goal
    {
        if ($goal->achieved_at !== null || ! GoalProgress::achieved($goal)) {
            return $goal;
        }

        $now = CarbonImmutable::now();

        // Atômico (achieved_at IS NULL na própria query, não num if lido
        // antes): duas chamadas concorrentes não gravam achieved_at duas
        // vezes. Sem tocar updated_at — isso não é uma edição do usuário.
        Goal::withoutTimestamps(fn () => Goal::query()->whereKey($goal->id)->whereNull('achieved_at')->update(['achieved_at' => $now]));

        $goal->achieved_at = $now;

        return $goal;
    }
}
