<?php

use Illuminate\Support\Facades\Hash;

it('atualiza o nome', function () {
    actingAsUser(['name' => 'Antigo']);

    $this->patchJson('/api/v1/me', ['name' => 'Novo'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Novo');
});

it('troca a senha exigindo a senha atual', function () {
    $user = actingAsUser(['password' => 'password123']);

    $this->patchJson('/api/v1/me', ['password' => 'novasenha123', 'password_confirmation' => 'novasenha123'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    $this->patchJson('/api/v1/me', [
        'current_password' => 'password123',
        'password' => 'novasenha123',
        'password_confirmation' => 'novasenha123',
    ])->assertOk();

    expect(Hash::check('novasenha123', $user->fresh()->password))->toBeTrue();
});

it('conta só-Google define senha sem senha atual', function () {
    $user = actingAsUser(['password' => null, 'google_id' => 'g-1']);

    $this->patchJson('/api/v1/me', ['password' => 'novasenha123', 'password_confirmation' => 'novasenha123'])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    expect(Hash::check('novasenha123', $user->fresh()->password))->toBeTrue();
});
