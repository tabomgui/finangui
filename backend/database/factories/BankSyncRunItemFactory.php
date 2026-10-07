<?php

namespace Database\Factories;

use App\Domain\Banking\Models\BankSyncRun;
use App\Domain\Banking\Models\BankSyncRunItem;
use App\Domain\Transactions\Enums\Direction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankSyncRunItem>
 */
class BankSyncRunItemFactory extends Factory
{
    protected $model = BankSyncRunItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => fn () => BankSyncRun::factory()->create()->id,
            'user_id' => fn (array $attributes) => BankSyncRun::query()->withoutGlobalScopes()
                ->findOrFail($attributes['run_id'])->user_id,
            'account_name' => 'Conta Corrente',
            'date' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'description' => 'Compra',
            'amount' => fake()->numberBetween(100, 50000),
            'direction' => Direction::Out,
        ];
    }
}
