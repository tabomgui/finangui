<?php

namespace Database\Factories;

use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Models\GoalContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalContribution>
 */
class GoalContributionFactory extends Factory
{
    protected $model = GoalContribution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => fn () => Goal::factory()->create()->id,
            // Mesmo dono da meta (ela pode ser de outro usuário em testes de isolamento).
            'user_id' => fn (array $attributes) => Goal::query()->withoutGlobalScopes()
                ->findOrFail($attributes['goal_id'])->user_id,
            'amount' => 10000,
            'date' => now()->toDateString(),
            'note' => null,
        ];
    }
}
