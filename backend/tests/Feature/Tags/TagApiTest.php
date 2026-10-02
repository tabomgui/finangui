<?php

use App\Domain\Tags\Models\Tag;
use App\Models\User;

it('cria, lista, renomeia e exclui tag', function () {
    actingAsUser();

    $id = $this->postJson('/api/v1/tags', ['name' => 'viagem-2026', 'color' => '#0ea5e9'])
        ->assertCreated()
        ->json('data.id');

    $this->getJson('/api/v1/tags')->assertJsonPath('data.0.name', 'viagem-2026');

    $this->patchJson("/api/v1/tags/{$id}", ['name' => 'viagem-japao'])
        ->assertOk()
        ->assertJsonPath('data.name', 'viagem-japao');

    $this->deleteJson("/api/v1/tags/{$id}")->assertNoContent();
    expect(Tag::count())->toBe(0);
});

it('não aceita nome duplicado para o mesmo usuário', function () {
    actingAsUser();
    Tag::factory()->create(['name' => 'viagem']);

    $this->postJson('/api/v1/tags', ['name' => 'viagem'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('permite o mesmo nome para usuários diferentes e isola a listagem', function () {
    $other = User::factory()->create();
    Tag::factory()->create(['user_id' => $other->id, 'name' => 'viagem']);

    actingAsUser();

    $this->postJson('/api/v1/tags', ['name' => 'viagem'])->assertCreated();
    $this->getJson('/api/v1/tags')->assertJsonCount(1, 'data');
});

it('exige nome ao atualizar quando enviado vazio', function () {
    actingAsUser();
    $tag = Tag::factory()->create();

    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('retorna 404 ao atualizar ou excluir tag de outro usuário', function () {
    $other = User::factory()->create();
    $tag = Tag::factory()->create(['user_id' => $other->id]);

    actingAsUser();

    $this->patchJson("/api/v1/tags/{$tag->id}", ['name' => 'nova'])->assertNotFound();
    $this->deleteJson("/api/v1/tags/{$tag->id}")->assertNotFound();
});
