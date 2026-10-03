<?php

namespace Database\Factories;

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
        return [
            'user_id' => fn () => Auth::id() ?? User::factory()->create()->id,
            'name' => 'Mercado',
            'priority' => 0,
            'is_active' => true,
            'match' => 'all',
            'conditions' => [
                ['field' => 'description', 'op' => 'contains', 'value' => 'mercado'],
            ],
            'actions' => [
                ['type' => 'set_category', 'category_id' => null],
            ],
        ];
    }
}
