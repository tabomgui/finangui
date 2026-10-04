<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recurrence>
 */
class RecurrenceFactory extends Factory
{
    protected $model = Recurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn () => Account::factory()->create()->id,
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()
                ->findOrFail($attributes['account_id'])->user_id,
            'description' => 'Aluguel',
            'amount' => 150000,
            'direction' => Direction::Out,
            'frequency' => Frequency::Monthly,
            'interval' => 1,
            'day_of_month' => 5,
            'starts_on' => '2026-01-05',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
