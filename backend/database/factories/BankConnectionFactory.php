<?php

namespace Database\Factories;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankConnection>
 */
class BankConnectionFactory extends Factory
{
    protected $model = BankConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => BankProviderName::Pluggy,
            'external_id' => (string) Str::uuid(),
            'status' => ConnectionStatus::PendingLink,
            'institution_name' => 'Banco Exemplo',
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => ConnectionStatus::Active, 'last_synced_at' => now()]);
    }

    public function needsReauth(): static
    {
        return $this->state(['status' => ConnectionStatus::NeedsReauth, 'last_error' => 'Reconexão necessária.']);
    }
}
