<?php

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Banking\Providers\FakeBankProviderFactory;
use App\Domain\Imports\Support\Content;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * Substitui App\Domain\Banking\Contracts\BankProviderFactory por uma
 * App\Domain\Banking\Providers\FakeBankProviderFactory que devolve $provider
 * (um FakeBankProvider novo, por padrão) para qualquer usuário — nenhum
 * teste de domínio/HTTP precisa de credenciais reais da Pluggy cadastradas
 * para exercitar o resto do Banking (mapeamento, sync, etc.). As rotas atrás
 * de App\Http\Middleware\EnsureBankingEnabled, porém, continuam exigindo um
 * App\Domain\Banking\Models\BankCredential de verdade no banco — ver
 * verifiedBankCredential() — porque esse gate lê dali direto, não desta
 * fábrica fake. Para simular "usuário sem credenciais" nos pontos que ainda
 * falam com esta fábrica (ex.: App\Domain\Banking\Actions\DisconnectConnection),
 * chame disableBankProvider() depois.
 */
function fakeBankProvider(?BankProvider $provider = null): FakeBankProvider|BankProvider
{
    $provider ??= new FakeBankProvider;

    app()->instance(BankProviderFactory::class, new FakeBankProviderFactory($provider));

    return $provider;
}

/**
 * Faz a fábrica fake (bindada por fakeBankProvider(), que precisa já ter
 * rodado) lançar BankingDisabled para qualquer usuário.
 */
function disableBankProvider(): void
{
    /** @var FakeBankProviderFactory $factory */
    $factory = app(BankProviderFactory::class);

    $factory->disable();
}

/**
 * Cadastra uma credencial da Pluggy verificada para $user — o bastante para
 * App\Http\Middleware\EnsureBankingEnabled (e banking_enabled em
 * App\Http\Resources\UserResource) considerarem a integração "ligada". As
 * rotas de bank-connections que falam com o provedor de verdade usam
 * fakeBankProvider() para isso (não a credencial em si).
 */
function verifiedBankCredential(User $user): BankCredential
{
    return BankCredential::factory()->create(['user_id' => $user->id]);
}

/**
 * Corrompe client_secret de $credential de um jeito que o cast `encrypted`
 * (e, portanto, Crypt::decryptString()) nunca mais consegue ler: cifra com
 * um Encrypter de chave diferente da do app. Simula uma APP_KEY trocada
 * entre o cadastro e agora. Ver também corruptBankCredentialClientId().
 */
function corruptBankCredentialSecret(BankCredential $credential): void
{
    corruptBankCredentialColumn($credential, 'client_secret');
}

/** Como corruptBankCredentialSecret(), mas corrompe client_id em vez de client_secret. */
function corruptBankCredentialClientId(BankCredential $credential): void
{
    corruptBankCredentialColumn($credential, 'client_id');
}

function corruptBankCredentialColumn(BankCredential $credential, string $column): void
{
    $otherKeyEncrypter = new Encrypter(Str::random(32), 'AES-256-CBC');

    DB::table('bank_credentials')
        ->where('id', $credential->id)
        ->update([$column => $otherKeyEncrypter->encryptString('nao-importa')]);
}
