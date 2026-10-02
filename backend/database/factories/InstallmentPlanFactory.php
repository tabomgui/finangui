<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentPlan>
 */
class InstallmentPlanFactory extends Factory
{
    protected $model = InstallmentPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn () => Account::factory()->creditCard()->create()->id,
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()
                ->findOrFail($attributes['account_id'])->user_id,
            'description' => 'Notebook',
            'total_amount' => 120000,
            'installments' => 10,
            'purchase_date' => '2026-03-05',
        ];
    }
}
