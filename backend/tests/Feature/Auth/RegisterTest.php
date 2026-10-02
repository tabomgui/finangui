<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;

$payload = [
    'name' => 'Gui',
    'email' => 'gui@example.com',
    'password' => 'password123',
    'password_confirmation' => 'password123',
];

it('bloqueia cadastro quando desligado', function () use ($payload) {
    config(['finangui.registration_enabled' => false]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'registration_closed');

    expect(User::count())->toBe(0);
});

it('cadastra, cria categorias e loga quando ligado', function () use ($payload) {
    config(['finangui.registration_enabled' => true]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertCreated()
        ->assertJsonPath('data.email', 'gui@example.com');

    $user = User::where('email', 'gui@example.com')->firstOrFail();

    expect(Category::query()->where('user_id', $user->id)->exists())->toBeTrue();
    $this->getJson('/api/v1/me')->assertOk();
});

it('valida o cadastro', function () {
    config(['finangui.registration_enabled' => true]);

    $this->postJson('/api/v1/auth/register', ['email' => 'invalido'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('recusa cadastro sem sessão stateful mesmo com payload válido', function () use ($payload) {
    config(['finangui.registration_enabled' => true]);

    $this->withHeader('Referer', 'http://evil.test')
        ->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(400)
        ->assertJsonPath('code', 'session_required');

    expect(User::count())->toBe(0);
});
