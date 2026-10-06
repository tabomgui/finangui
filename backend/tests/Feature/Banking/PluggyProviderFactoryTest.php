<?php

use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * for() consulta BankCredential sob o global scope de BelongsToUser (ver
 * App\Domain\Banking\Providers\Pluggy\PluggyProviderFactory — não usa mais
 * withoutGlobalScopes()), que falha fechado sem usuário autenticado: todo
 * teste aqui roda dentro de App\Support\UserContext::run(), o mesmo que
 * App\Domain\Banking\Jobs\SyncConnection usa de verdade.
 */
beforeEach(function () {
    config(['services.pluggy.base_url' => 'https://api.pluggy.ai']);
});

it('monta um PluggyProvider com as credenciais cadastradas do usuário', function () {
    $user = User::factory()->create();
    BankCredential::factory()->create([
        'user_id' => $user->id, 'client_id' => 'client-a', 'client_secret' => 'secret-a',
    ]);

    Http::fake([
        'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-a']),
        'api.pluggy.ai/connect_token' => Http::response(['accessToken' => 'token-a']),
    ]);

    $provider = UserContext::run($user, fn () => app(BankProviderFactory::class)->for($user));

    expect($provider)->toBeInstanceOf(PluggyProvider::class);

    $provider->connectToken('user:'.$user->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/auth')) {
            return false;
        }

        $body = $request->data();

        return $body['clientId'] === 'client-a' && $body['clientSecret'] === 'secret-a';
    });
});

it('dois usuários com credenciais diferentes autenticam na Pluggy com client_id/secret próprios', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    BankCredential::factory()->create(['user_id' => $userA->id, 'client_id' => 'client-a', 'client_secret' => 'secret-a']);
    BankCredential::factory()->create(['user_id' => $userB->id, 'client_id' => 'client-b', 'client_secret' => 'secret-b']);

    Http::fake([
        'api.pluggy.ai/auth' => Http::sequence()->push(['apiKey' => 'key-a'])->push(['apiKey' => 'key-b']),
        'api.pluggy.ai/connect_token' => Http::response(['accessToken' => 'token-x']),
    ]);

    $providerA = UserContext::run($userA, fn () => app(BankProviderFactory::class)->for($userA));
    $providerB = UserContext::run($userB, fn () => app(BankProviderFactory::class)->for($userB));

    $providerA->connectToken('user:'.$userA->id);
    $providerB->connectToken('user:'.$userB->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/auth')) {
            return false;
        }

        return $request->data()['clientId'] === 'client-a';
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/auth')) {
            return false;
        }

        return $request->data()['clientId'] === 'client-b';
    });

    // O connect-token de B usa a key de B, não a de A (duas instâncias de
    // PluggyProvider, cada uma com sua própria API key cacheada).
    Http::assertSent(function ($request) use ($userB) {
        if (! str_contains($request->url(), '/connect_token')) {
            return false;
        }

        $body = $request->data();

        return ($body['options']['clientUserId'] ?? null) === 'user:'.$userB->id
            && $request->hasHeader('X-API-KEY', 'key-b');
    });

    // Cada usuário cacheia a própria API key, sob uma chave que mistura
    // user_id e client_id — nunca a mesma entrada de cache para os dois.
    expect(Cache::get(PluggyProvider::apiKeyCacheKeyFor($userA->id, 'client-a')))->not->toBeNull()
        ->and(Cache::get(PluggyProvider::apiKeyCacheKeyFor($userB->id, 'client-b')))->not->toBeNull();
});

it('usuário sem credenciais cadastradas → BankingDisabled', function () {
    $user = User::factory()->create();

    expect(fn () => UserContext::run($user, fn () => app(BankProviderFactory::class)->for($user)))
        ->toThrow(BankingDisabled::class);
});

it('credenciais ainda não verificadas (verified_at nulo) → BankingDisabled', function () {
    $user = User::factory()->create();
    BankCredential::factory()->unverified()->create(['user_id' => $user->id]);

    expect(fn () => UserContext::run($user, fn () => app(BankProviderFactory::class)->for($user)))
        ->toThrow(BankingDisabled::class);
});

it('credenciais de outro usuário nunca são usadas para montar o provedor deste', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    BankCredential::factory()->create(['user_id' => $other->id, 'client_id' => 'client-other', 'client_secret' => 'secret-other']);

    expect(fn () => UserContext::run($user, fn () => app(BankProviderFactory::class)->for($user)))
        ->toThrow(BankingDisabled::class);
});
