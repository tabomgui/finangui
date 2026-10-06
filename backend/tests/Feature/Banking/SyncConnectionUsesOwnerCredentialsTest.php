<?php

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Prova a fiação de ponta a ponta com a fábrica real (PluggyProviderFactory):
 * App\Domain\Banking\Jobs\SyncConnection autentica na Pluggy com as
 * credenciais do dono da conexão, nunca com as de outro usuário — mesmo os
 * dois tendo credenciais cadastradas ao mesmo tempo.
 */
it('o sync usa as credenciais do dono da conexão, nunca as de outro usuário', function () {
    config(['services.pluggy.base_url' => 'https://api.pluggy.ai']);

    $owner = User::factory()->create();
    $other = User::factory()->create();
    BankCredential::factory()->create(['user_id' => $owner->id, 'client_id' => 'owner-client', 'client_secret' => 'owner-secret']);
    BankCredential::factory()->create(['user_id' => $other->id, 'client_id' => 'other-client', 'client_secret' => 'other-secret']);

    $connection = BankConnection::factory()->active()->create(['user_id' => $owner->id, 'external_id' => 'item-owner']);

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-owner']),
        'api.pluggy.ai/items/*' => Http::response(['id' => 'item-owner', 'status' => 'UPDATED']),
        'api.pluggy.ai/accounts*' => Http::response(['results' => []]),
    ]);

    app()->call([new SyncConnection($connection->id), 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/auth')) {
            return false;
        }

        $body = $request->data();

        return $body['clientId'] === 'owner-client' && $body['clientSecret'] === 'owner-secret';
    });

    Http::assertNotSent(function ($request) {
        if (! str_contains($request->url(), '/auth')) {
            return false;
        }

        return $request->data()['clientId'] === 'other-client';
    });

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Active)
        ->and($connection->last_error)->toBeNull();
});
