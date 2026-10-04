<?php

namespace App\Domain\Goals\Actions;

use App\Domain\Goals\Errors\GoalContributionsNotAllowed;
use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Models\GoalContribution;

/**
 * Registra um aporte (ou retirada, amount negativo) numa meta sem conta
 * vinculada. Meta com conta recebe o progresso do saldo dela — aportar nela
 * não faz sentido e é rejeitado como erro de negócio.
 */
final class CreateGoalContribution
{
    public function __construct(
        private readonly RefreshGoalAchievement $refreshGoalAchievement,
    ) {}

    /**
     * @param  array{amount: int, date: string, note?: string|null}  $input  dados já validados
     */
    public function handle(Goal $goal, array $input): GoalContribution
    {
        if ($goal->account_id !== null) {
            throw new GoalContributionsNotAllowed;
        }

        $contribution = $goal->contributions()->create($input);

        $this->refreshGoalAchievement->handle($goal);

        return $contribution;
    }
}
