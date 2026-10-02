<?php

namespace Database\Factories;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => Auth::id() ?? User::factory()->create()->id,
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'kind' => CategoryKind::Expense,
            'icon' => 'tag',
            'color' => '#71717a',
            'is_transfer' => false,
            'is_archived' => false,
        ];
    }

    public function income(): static
    {
        return $this->state(['kind' => CategoryKind::Income]);
    }

    public function transfer(): static
    {
        return $this->state(['is_transfer' => true]);
    }
}
