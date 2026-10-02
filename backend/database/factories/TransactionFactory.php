<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $description = fake()->randomElement(['Mercado Extra', 'Uber *trip', 'Netflix', 'Padaria', 'Pix recebido']);

        return [
            'account_id' => fn () => Account::factory()->create()->id,
            // Mesmo dono da conta (a conta pode ser de outro usuário em testes de isolamento).
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()
                ->findOrFail($attributes['account_id'])->user_id,
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'amount' => fake()->numberBetween(100, 50000),
            'direction' => Direction::Out,
            'currency' => 'BRL',
            'description' => $description,
            'original_description' => fn (array $attributes) => $attributes['description'],
            'description_locked' => false,
            'category_id' => null,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'is_ignored' => false,
        ];
    }

    public function income(): static
    {
        return $this->state(['direction' => Direction::In]);
    }
}
