<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferSuggestion>
 */
class TransferSuggestionFactory extends Factory
{
    protected $model = TransferSuggestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'out_transaction_id' => fn () => Transaction::factory()->create()->id,
            // Mesmo dono da perna de saída, em conta diferente.
            'in_transaction_id' => fn (array $attributes) => Transaction::factory()->create([
                'account_id' => fn () => Account::factory()->create([
                    'user_id' => Transaction::query()->withoutGlobalScopes()
                        ->findOrFail($attributes['out_transaction_id'])->user_id,
                ])->id,
                'direction' => Direction::In,
            ])->id,
            'user_id' => fn (array $attributes) => Transaction::query()->withoutGlobalScopes()
                ->findOrFail($attributes['out_transaction_id'])->user_id,
            'score' => 0.6,
            'status' => TransferSuggestionStatus::Pending,
        ];
    }
}
