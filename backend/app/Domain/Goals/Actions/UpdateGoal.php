<?php

namespace App\Domain\Goals\Actions;

use App\Domain\Goals\Errors\GoalHasContributions;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Support\GoalProgress;

/**
 * Edita uma meta. Vincular conta a uma meta que já tem aportes é rejeitado
 * como erro de negócio — os dois jeitos de medir progresso não convivem
 * (ver Goal). achieved_at só é desfeito aqui, e só quando target_amount
 * muda para um valor que o progresso atual não alcança mais: editar outro
 * campo nunca desfaz uma meta já atingida (RefreshGoalAchievement, chamado
 * depois desta action, pode voltar a marcá-la se o progresso ainda bate).
 */
final class UpdateGoal
{
    /**
     * @param  array<string, mixed>  $input  dados já validados
     */
    public function handle(Goal $goal, array $input): Goal
    {
        if (array_key_exists('account_id', $input)
            && $input['account_id'] !== null
            && $input['account_id'] !== $goal->account_id
            && $goal->contributions()->exists()) {
            throw new GoalHasContributions;
        }

        $targetChanged = array_key_exists('target_amount', $input) && $input['target_amount'] !== $goal->target_amount->cents;

        $goal->update($input);

        if ($targetChanged && $goal->achieved_at !== null && ! GoalProgress::achieved($goal)) {
            $goal->forceFill(['achieved_at' => null])->save();
        }

        return $goal;
    }
}
