<?php

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Imports\Support\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function actingAsUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    test()->actingAs($user);

    return $user;
}

/** Conteúdo normalizado de um arquivo em tests/Fixtures/imports. */
function importFixture(string $name): string
{
    return Content::normalize(file_get_contents(base_path("tests/Fixtures/imports/{$name}")));
}

/**
 * Corpo decodificado de um arquivo em tests/Fixtures/pluggy.
 *
 * @return array<string, mixed>
 */
function pluggyFixture(string $name): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(file_get_contents(base_path("tests/Fixtures/pluggy/{$name}")), true);

    return $decoded;
}

/**
 * Substitui App\Domain\Banking\Contracts\BankProviderFactory por uma fábrica
 * fake que devolve $provider (um FakeBankProvider novo, por padrão) para
 * qualquer usuário — nenhum teste de domínio/HTTP precisa de credenciais
 * reais da Pluggy cadastradas para exercitar o Banking. Espelha
 * $provider->enabled() em BankingDisabled, para os testes que simulam
 * "usuário sem credenciais" com FakeBankProvider::setEnabled(false)
 * continuarem funcionando do jeito que funcionavam com o provedor global.
 */
function fakeBankProvider(?BankProvider $provider = null): FakeBankProvider|BankProvider
{
    $provider ??= new FakeBankProvider;

    app()->instance(BankProviderFactory::class, new class($provider) implements BankProviderFactory
    {
        public function __construct(private readonly BankProvider $provider) {}

        public function for(User $user): BankProvider
        {
            if (! $this->provider->enabled()) {
                throw new BankingDisabled;
            }

            return $this->provider;
        }
    });

    return $provider;
}
