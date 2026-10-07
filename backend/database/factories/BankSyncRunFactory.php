<?php

namespace Database\Factories;

use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankSyncRun>
 */
class BankSyncRunFactory extends Factory
{
    protected $model = BankSyncRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => fn () => BankConnection::factory()->create()->id,
            'user_id' => fn (array $attributes) => BankConnection::query()->withoutGlobalScopes()
                ->findOrFail($attributes['connection_id'])->user_id,
            'trigger' => SyncTrigger::Scheduled,
            'job_uuid' => (string) Str::uuid(),
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ];
    }

    public function success(): static
    {
        return $this->state([
            'status' => SyncRunStatus::Success,
            'finished_at' => now(),
        ]);
    }

    public function error(string $message = 'Não foi possível falar com o banco.'): static
    {
        return $this->state([
            'status' => SyncRunStatus::Error,
            'finished_at' => now(),
            'error' => $message,
        ]);
    }
}
