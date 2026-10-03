<?php

namespace Database\Factories;

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Models\Rule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    protected $model = Rule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Mesmo usuário para a regra e para a categoria padrão da ação: o
        // motor exige um category_id inteiro positivo de uma categoria real.
        $userId = Auth::id() ?? User::factory()->create()->id;

        return [
            'user_id' => $userId,
            'name' => 'Mercado',
            'priority' => 0,
            'is_active' => true,
            'match' => 'all',
            'conditions' => [
                ['field' => 'description', 'op' => 'contains', 'value' => 'mercado'],
            ],
            'actions' => fn () => [
                ['type' => 'set_category', 'category_id' => Category::factory()->create(['user_id' => $userId])->id],
            ],
        ];
    }
}
