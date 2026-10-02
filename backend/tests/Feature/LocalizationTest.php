<?php

it('responde mensagens de validação em português', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', fn (string $message) => str_contains($message, 'obrigatório'));
});
