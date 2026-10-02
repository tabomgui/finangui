<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardStatement>
 */
class CardStatementFactory extends Factory
{
    protected $model = CardStatement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn () => Account::factory()->creditCard()->create()->id,
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()
                ->findOrFail($attributes['account_id'])->user_id,
            'closing_date' => '2026-03-03',
            'due_date' => '2026-03-10',
        ];
    }
}
