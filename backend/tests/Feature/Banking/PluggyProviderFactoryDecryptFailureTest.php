<?php

use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simula uma credencial que não descriptografa mais (ex.: APP_KEY trocada
 * entre o cadastro e agora): grava client_secret cru, cifrado por um
 * Encrypter com uma chave diferente da do app — Crypt::decryptString() (e,
 * portanto, o cast `encrypted` do model) nunca vai conseguir ler isso.
 */
function corruptBankCredentialSecret(BankCredential $credential): void
{
    $otherKeyEncrypter = new Encrypter(Str::random(32), 'AES-256-CBC');

    DB::table('bank_credentials')
        ->where('id', $credential->id)
        ->update(['client_secret' => $otherKeyEncrypter->encryptString('nao-importa')]);
}

it('client_secret indecifrável: loga um warning com só user_id e lança BankingDisabled, sem vazar o valor', function () {
    Log::spy();
    $user = actingAsUser();
    $credential = BankCredential::factory()->create(['user_id' => $user->id]);
    corruptBankCredentialSecret($credential);

    expect(fn () => app(BankProviderFactory::class)->for($user))->toThrow(BankingDisabled::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Pluggy: credencial do usuário não descriptografa mais; tratando como sem credenciais.', ['user_id' => $user->id]);
});

it('DELETE /bank-connections/{id} funciona mesmo com client_secret indecifrável (tratada como sem credenciais)', function () {
    $user = actingAsUser();
    $credential = BankCredential::factory()->create(['user_id' => $user->id]);
    corruptBankCredentialSecret($credential);

    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id, 'external_id' => 'item-x']);

    $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

    expect(BankConnection::find($connection->id))->toBeNull();
});

it('connect-token com client_secret indecifrável → 409 banking_disabled', function () {
    $user = actingAsUser();
    $credential = BankCredential::factory()->create(['user_id' => $user->id]);
    corruptBankCredentialSecret($credential);

    $this->postJson('/api/v1/bank-connections/connect-token')
        ->assertStatus(409)
        ->assertJsonPath('code', 'banking_disabled');
});

it('SyncConnection com client_secret indecifrável termina em error, sem relançar e sem falar com a Pluggy', function () {
    $owner = User::factory()->create();
    $credential = BankCredential::factory()->create(['user_id' => $owner->id]);
    corruptBankCredentialSecret($credential);

    $connection = BankConnection::factory()->active()->create(['user_id' => $owner->id, 'external_id' => 'item-x']);

    Http::fake();

    app()->call([new SyncConnection($connection->id), 'handle']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error);

    Http::assertNothingSent();
});
