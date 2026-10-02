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

it('faz logout', function () {
    actingAsUser();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
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
        'finangui.registration_enabled' => false,
    ]);

    $this->getJson('/api/v1/auth/status')
        ->assertOk()
        ->assertExactJson(['data' => ['google_login_enabled' => false, 'registration_enabled' => false]]);

    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

    $this->getJson('/api/v1/auth/status')->assertJsonPath('data.google_login_enabled', true);
});
