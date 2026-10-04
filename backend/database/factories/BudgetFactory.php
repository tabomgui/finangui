<?php

namespace Database\Factories;

use App\Domain\Budgets\Models\Budget;
use App\Domain\Categories\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn () => Category::factory()->create()->id,
            // Mesmo dono da categoria (ela pode ser de outro usuário em testes de isolamento).
            'user_id' => fn (array $attributes) => Category::query()->withoutGlobalScopes()
                ->findOrFail($attributes['category_id'])->user_id,
            'amount' => 50000,
            'month' => null,
        ];
    }

    public function forMonth(string $month): static
    {
        return $this->state(['month' => $month.'-01']);
    }
}
