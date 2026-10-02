<?php

use App\Models\User;

it('loga com credenciais válidas', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'password123'])
        ->assertOk()
        ->assertJsonPath('data.email', 'gui@example.com')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password');
});

it('loga com o email em caixa diferente da armazenada', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'GUI@example.com', 'password' => 'password123'])
        ->assertOk()
        ->assertJsonPath('data.email', 'gui@example.com');
});

it('recusa senha errada', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'errada'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('recusa login de conta sem senha (só Google)', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => null]);

    $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'qualquer'])
        ->assertStatus(422);
});

it('recusa login sem sessão stateful mesmo com credenciais corretas', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->withHeader('Referer', 'http://evil.test')
        ->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'password123'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'session_required');

    $this->assertGuest();
});

it('limita tentativas de login por email', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'errada'])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'errada'])
        ->assertStatus(429);
});

it('recusa email em formato inválido sem derrubar o rate limiter', function () {
    $this->postJson('/api/v1/auth/login', ['email' => ['x'], 'password' => 'a'])
        ->assertStatus(422);
});

it('faz logout e invalida a sessão', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'password123'])
        ->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('regenera o id da sessão no login', function () {
    User::factory()->create(['email' => 'gui@example.com', 'password' => 'password123']);

    $this->withCredentials();
    $cookieName = config('session.cookie');

    $before = $this->getJson('/api/v1/auth/status');
    $idBefore = session()->getId();
    $cookie = $before->getCookie($cookieName, false)?->getValue();

    // Prova que o replay do cookie realmente mantém a mesma sessão (senão o teste seria vazio).
    $this->withUnencryptedCookie($cookieName, $cookie)->getJson('/api/v1/auth/status');
    expect(session()->getId())->toBe($idBefore);

    $this->withUnencryptedCookie($cookieName, $cookie)
        ->postJson('/api/v1/auth/login', ['email' => 'gui@example.com', 'password' => 'password123'])
        ->assertOk();

    expect(session()->getId())->not->toBe($idBefore);
});

it('exige autenticação em /me', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('retorna o usuário autenticado em /me', function () {
    $user = actingAsUser(['name' => 'Gui']);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Gui')
        ->assertJsonPath('data.google_linked', false);
});

it('informa o status de auth da instância', function () {
    config([
        'services.google.client_id' => null,
        'services.google.client_secret' => null,
        'services.google.redirect' => null,
        'finangui.registration_enabled' => false,
    ]);

    $this->getJson('/api/v1/auth/status')
        ->assertOk()
        ->assertExactJson(['data' => ['google_login_enabled' => false, 'registration_enabled' => false]]);

    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        'services.google.redirect' => 'https://finangui.test/api/auth/google/callback',
    ]);

    $this->getJson('/api/v1/auth/status')->assertJsonPath('data.google_login_enabled', true);
});

it('considera o Google desconfigurado sem redirect, mesmo com client id e secret', function () {
    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        'services.google.redirect' => null,
    ]);

    $this->getJson('/api/v1/auth/status')->assertJsonPath('data.google_login_enabled', false);
});
