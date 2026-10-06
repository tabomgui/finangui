<?php

use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pluggy.base_url' => 'https://api.pluggy.ai']);
});

describe('GET /bank-credentials', function () {
    it('sem credencial cadastrada: configured false, sem client_id_hint/verified_at', function () {
        actingAsUser();

        $this->getJson('/api/v1/bank-credentials')
            ->assertOk()
            ->assertJson(['data' => ['configured' => false, 'provider' => 'pluggy']])
            ->assertJsonMissingPath('data.client_id_hint')
            ->assertJsonMissingPath('data.verified_at');
    });

    it('com credencial verificada: configured true, client_id_hint e verified_at, nunca id/secret', function () {
        $user = actingAsUser();
        BankCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => '11111111-1111-1111-1111-111111111abc',
            'client_secret' => 'super-secret-value',
            'client_id_hint' => '1abc',
        ]);

        $response = $this->getJson('/api/v1/bank-credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.provider', 'pluggy')
            ->assertJsonPath('data.client_id_hint', '1abc');

        expect($response->json('data.verified_at'))->not->toBeNull();
        expect($response->getContent())->not->toContain('super-secret-value')
            ->and($response->getContent())->not->toContain('11111111-1111-1111-1111-111111111abc');
    });

    it('isolamento: credencial de outro usuário não aparece', function () {
        $other = User::factory()->create();
        BankCredential::factory()->create(['user_id' => $other->id]);
        actingAsUser();

        $this->getJson('/api/v1/bank-credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
    });
});

describe('PUT /bank-credentials', function () {
    it('aceita e grava: upsert, verified_at, provider, hint; nunca devolve segredo', function () {
        $user = actingAsUser();

        Http::fake([
            'api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-1']),
        ]);

        $response = $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '22222222-2222-2222-2222-22222222dead',
            'client_secret' => 'the-secret-value',
        ])->assertOk();

        expect($response->getContent())->not->toContain('the-secret-value')
            ->and($response->getContent())->not->toContain('22222222-2222-2222-2222-22222222dead');

        $response->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.client_id_hint', 'dead');

        $credential = BankCredential::query()->where('user_id', $user->id)->first();
        expect($credential)->not->toBeNull()
            ->and($credential->client_id)->toBe('22222222-2222-2222-2222-22222222dead')
            ->and($credential->client_secret)->toBe('the-secret-value')
            ->and($credential->verified_at)->not->toBeNull();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/auth')) {
                return false;
            }

            $body = $request->data();

            return $body['clientId'] === '22222222-2222-2222-2222-22222222dead'
                && $body['clientSecret'] === 'the-secret-value';
        });
    });

    it('credenciais recusadas (401) → 422 em client_secret, nada é gravado', function () {
        actingAsUser();

        Http::fake(['api.pluggy.ai/auth' => Http::response([], 401)]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '33333333-3333-3333-3333-333333333333',
            'client_secret' => 'wrong-secret',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_secret']);

        expect(BankCredential::query()->count())->toBe(0);
    });

    it('credenciais recusadas (403) → 422 em client_secret', function () {
        actingAsUser();

        Http::fake(['api.pluggy.ai/auth' => Http::response([], 403)]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '44444444-4444-4444-4444-444444444444',
            'client_secret' => 'wrong-secret',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_secret']);
    });

    it('Pluggy fora do ar (5xx) → 503 provider_unavailable, nada é gravado', function () {
        actingAsUser();

        Http::fake(['api.pluggy.ai/auth' => Http::response([], 500)]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '55555555-5555-5555-5555-555555555555',
            'client_secret' => 'some-secret',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_unavailable');

        expect(BankCredential::query()->count())->toBe(0);
    });

    it('formato inválido (client_id não-uuid) → 422', function () {
        actingAsUser();

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'not-a-uuid',
            'client_secret' => 'some-secret',
        ])->assertStatus(422)->assertJsonValidationErrors(['client_id']);
    });

    it('trocar apenas o secret do mesmo client_id é permitido mesmo com conexões', function () {
        $user = actingAsUser();
        $credential = BankCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => '66666666-6666-6666-6666-666666666666',
            'client_secret' => 'old-secret',
        ]);
        BankConnection::factory()->create(['user_id' => $user->id]);

        Http::fake(['api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-2'])]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '66666666-6666-6666-6666-666666666666',
            'client_secret' => 'new-secret',
        ])->assertOk();

        expect($credential->refresh()->client_secret)->toBe('new-secret');
    });

    it('trocar client_id com conexões bancárias → 409 bank_credentials_in_use, nada é alterado', function () {
        $user = actingAsUser();
        $credential = BankCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => '77777777-7777-7777-7777-777777777777',
            'client_secret' => 'old-secret',
        ]);
        BankConnection::factory()->create(['user_id' => $user->id]);

        Http::fake(['api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-3'])]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => '88888888-8888-8888-8888-888888888888',
            'client_secret' => 'new-secret',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'bank_credentials_in_use');

        expect($credential->refresh()->client_id)->toBe('77777777-7777-7777-7777-777777777777')
            ->and($credential->refresh()->client_secret)->toBe('old-secret');

        Http::assertNothingSent();
    });

    it('trocar client_id sem conexões bancárias é permitido', function () {
        $user = actingAsUser();
        BankCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => '99999999-9999-9999-9999-999999999999',
            'client_secret' => 'old-secret',
        ]);

        Http::fake(['api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-4'])]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'client_secret' => 'new-secret',
        ])->assertOk();

        $credential = BankCredential::query()->where('user_id', $user->id)->first();
        expect($credential->client_id)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    });

    it('usuário com conexões mas sem credencial cadastrada pode salvar (conexões da era de env global)', function () {
        $user = actingAsUser();
        BankConnection::factory()->create(['user_id' => $user->id]);

        Http::fake(['api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-5'])]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'client_secret' => 'brand-new-secret',
        ])->assertOk();

        expect(BankCredential::query()->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('testar a credencial nova limpa a key cacheada da mesma combinação usuário/client_id (não reaproveita key do secret antigo)', function () {
        $user = actingAsUser();
        BankCredential::factory()->create([
            'user_id' => $user->id,
            'client_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'client_secret' => 'old-secret',
        ]);
        // Simula uma API key ainda "válida" cacheada para esse client_id.
        Cache::put(PluggyProvider::apiKeyCacheKeyFor($user->id, 'cccccccc-cccc-cccc-cccc-cccccccccccc'), 'stale', now()->addHour());

        Http::fake(['api.pluggy.ai/auth' => Http::response([], 401)]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'client_secret' => 'wrong-new-secret',
        ])->assertStatus(422);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/auth'));
    });

    it('isolamento: salvar credencial de um usuário não altera a de outro', function () {
        $other = User::factory()->create();
        BankCredential::factory()->create(['user_id' => $other->id, 'client_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd']);
        actingAsUser();

        Http::fake(['api.pluggy.ai/auth' => Http::response(['apiKey' => 'key-6'])]);

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'client_secret' => 'some-secret',
        ])->assertOk();

        expect(BankCredential::query()->withoutGlobalScopes()->where('user_id', $other->id)->first()->client_id)->toBe('dddddddd-dddd-dddd-dddd-dddddddddddd');
    });

    it('throttle próprio: a 11ª chamada em um minuto responde 429, sem afetar outro prefixo', function () {
        actingAsUser();

        Http::fake(['api.pluggy.ai/auth' => Http::response([], 401)]);

        for ($i = 0; $i < 10; $i++) {
            $this->putJson('/api/v1/bank-credentials', [
                'client_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                'client_secret' => 'wrong-secret',
            ]);
        }

        $this->putJson('/api/v1/bank-credentials', [
            'client_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'client_secret' => 'wrong-secret',
        ])->assertStatus(429);

        expect($this->postJson('/api/v1/rules/preview', [])->status())->not->toBe(429);
    });
});

describe('DELETE /bank-credentials', function () {
    it('com conexões bancárias → 409 bank_credentials_in_use, nada é removido', function () {
        $user = actingAsUser();
        BankCredential::factory()->create(['user_id' => $user->id]);
        BankConnection::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/v1/bank-credentials')
            ->assertStatus(409)
            ->assertJsonPath('code', 'bank_credentials_in_use');

        expect(BankCredential::query()->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('sem conexões bancárias: remove e responde 204', function () {
        $user = actingAsUser();
        BankCredential::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/v1/bank-credentials')->assertNoContent();

        expect(BankCredential::query()->where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('sem credencial cadastrada: ainda responde 204 (idempotente)', function () {
        actingAsUser();

        $this->deleteJson('/api/v1/bank-credentials')->assertNoContent();
    });

    it('isolamento: remover a credencial de um usuário não afeta a de outro', function () {
        $other = User::factory()->create();
        BankCredential::factory()->create(['user_id' => $other->id]);

        $user = actingAsUser();
        BankCredential::factory()->create(['user_id' => $user->id]);

        $this->deleteJson('/api/v1/bank-credentials')->assertNoContent();

        expect(BankCredential::query()->withoutGlobalScopes()->where('user_id', $other->id)->exists())->toBeTrue();
    });
});
