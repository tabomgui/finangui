<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\TransactionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn () => Account::factory()->create()->id,
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()
                ->findOrFail($attributes['account_id'])->user_id,
            'format' => ImportFormat::Inter,
            'source' => TransactionSource::Csv->value,
            'filename' => 'extrato.csv',
            'status' => 'pending',
            'rows' => [],
            'stats' => null,
            'undo' => null,
        ];
    }
}
