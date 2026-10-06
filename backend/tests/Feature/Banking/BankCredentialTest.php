<?php

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Models\BankCredential;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('guarda client_id e client_secret criptografados no banco (consulta crua não traz o valor em claro)', function () {
    $user = actingAsUser();
    $credential = BankCredential::factory()->create([
        'user_id' => $user->id,
        'client_id' => 'plain-client-id',
        'client_secret' => 'plain-client-secret',
    ]);

    $raw = DB::table('bank_credentials')->where('id', $credential->id)->first();

    expect($raw->client_id)->not->toBe('plain-client-id')
        ->and($raw->client_secret)->not->toBe('plain-client-secret')
        ->and(Crypt::decryptString($raw->client_id))->toBe('plain-client-id')
        ->and(Crypt::decryptString($raw->client_secret))->toBe('plain-client-secret');

    expect($credential->client_id)->toBe('plain-client-id')
        ->and($credential->client_secret)->toBe('plain-client-secret')
        ->and($credential->provider)->toBe(BankProviderName::Pluggy);
});

it('nunca expõe client_id/client_secret em toArray()', function () {
    $user = actingAsUser();
    $credential = BankCredential::factory()->create(['user_id' => $user->id]);

    expect($credential->toArray())->not->toHaveKeys(['client_id', 'client_secret']);
});

it('é único por usuário e provedor', function () {
    $user = actingAsUser();
    BankCredential::factory()->create(['user_id' => $user->id]);

    expect(fn () => BankCredential::factory()->create(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});
