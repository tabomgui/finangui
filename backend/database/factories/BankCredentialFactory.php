<?php

namespace Database\Factories;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Models\BankCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankCredential>
 */
class BankCredentialFactory extends Factory
{
    protected $model = BankCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Valores inventados (repositório público): nunca uma credencial real da Pluggy.
        $clientId = (string) Str::uuid();

        return [
            'provider' => BankProviderName::Pluggy,
            'client_id' => $clientId,
            'client_secret' => Str::random(40),
            'client_id_hint' => '••••'.mb_substr($clientId, -4),
            'verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['verified_at' => null]);
    }
}
