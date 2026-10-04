<?php

namespace App\Domain\Goals\Actions;

use App\Domain\Goals\Models\GoalContribution;

/**
 * Remove um aporte. Não há o que recalcular em achieved_at: removê-lo só
 * pode reduzir o progresso, e uma meta já atingida nunca deixa de estar
 * (ver RefreshGoalAchievement).
 */
final class DeleteGoalContribution
{
    public function handle(GoalContribution $contribution): void
    {
        $contribution->delete();
    }
}
