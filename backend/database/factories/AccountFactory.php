<?php

namespace Database\Factories;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => Auth::id() ?? User::factory()->create()->id,
            'name' => fake()->randomElement(['Inter', 'Nubank', 'Carteira', 'Itaú', 'C6']),
            'type' => AccountType::Checking,
            'currency' => 'BRL',
            'opening_balance' => 0,
            'color' => '#f97316',
            'icon' => 'landmark',
            'is_archived' => false,
        ];
    }

    public function archived(): static
    {
        return $this->state(['is_archived' => true]);
    }

    public function creditCard(int $closingDay = 3, int $dueDay = 10, int $limit = 500000): static
    {
        return $this->state([
            'name' => 'Cartão',
            'type' => AccountType::CreditCard,
            'icon' => 'credit-card',
            'credit_limit' => $limit,
            'closing_day' => $closingDay,
            'due_day' => $dueDay,
            'last_four' => '4242',
        ]);
    }
}
