<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Goals\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'target_amount' => 100000,
            'target_date' => null,
            'account_id' => null,
            'color' => '#10b981',
            'icon' => 'piggy-bank',
            'achieved_at' => null,
        ];
    }

    public function withAccount(): static
    {
        return $this->state(fn () => ['account_id' => Account::factory()->create()->id]);
    }

    public function achieved(): static
    {
        return $this->state(['achieved_at' => now()]);
    }
}
