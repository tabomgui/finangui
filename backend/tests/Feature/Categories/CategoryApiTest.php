<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;

it('lista só as categorias do usuário, sem arquivadas por padrão', function () {
    $other = User::factory()->create();
    Category::factory()->create(['user_id' => $other->id, 'name' => 'Do outro']);

    actingAsUser();
    Category::factory()->create(['name' => 'Mercado']);
    Category::factory()->create(['name' => 'Antiga', 'is_archived' => true]);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mercado');

    $this->getJson('/api/v1/categories?include_archived=1')->assertJsonCount(2, 'data');
});

it('cria categoria raiz e subcategoria', function () {
    actingAsUser();

    $parent = $this->postJson('/api/v1/categories', [
        'name' => 'Alimentação', 'kind' => 'expense', 'icon' => 'utensils', 'color' => '#f97316',
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/categories', [
        'name' => 'Mercado', 'kind' => 'expense', 'parent_id' => $parent['id'],
    ])->assertCreated()
        ->assertJsonPath('data.parent_id', $parent['id'])
        ->assertJsonPath('data.is_transfer', false);
});

it('exige que a subcategoria tenha o mesmo tipo do pai', function () {
    actingAsUser();
    $parent = Category::factory()->create(['kind' => 'expense']);

    $this->postJson('/api/v1/categories', ['name' => 'Bônus', 'kind' => 'income', 'parent_id' => $parent->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('não permite mais de um nível de hierarquia', function () {
    actingAsUser();
    $root = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $root->id]);

    $this->postJson('/api/v1/categories', ['name' => 'Neta', 'kind' => 'expense', 'parent_id' => $child->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('não aceita nome duplicado sob o mesmo pai, mas aceita sob pais diferentes', function () {
    actingAsUser();
    $a = Category::factory()->create(['name' => 'Moradia']);
    $b = Category::factory()->create(['name' => 'Compras']);
    Category::factory()->create(['name' => 'Casa', 'parent_id' => $a->id]);

    $this->postJson('/api/v1/categories', ['name' => 'Casa', 'kind' => 'expense', 'parent_id' => $a->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/categories', ['name' => 'Casa', 'kind' => 'expense', 'parent_id' => $b->id])
        ->assertCreated();
});

it('não aceita pai de outro usuário', function () {
    $other = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $other->id]);

    actingAsUser();

    $this->postJson('/api/v1/categories', ['name' => 'X', 'kind' => 'expense', 'parent_id' => $foreign->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('atualiza categoria', function () {
    actingAsUser();
    $category = Category::factory()->create(['name' => 'Mercado']);

    $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Supermercado', 'is_transfer' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'Supermercado')
        ->assertJsonPath('data.is_transfer', true);
});

it('não transforma categoria com filhas em subcategoria', function () {
    actingAsUser();
    $root = Category::factory()->create();
    Category::factory()->create(['parent_id' => $root->id]);
    $other = Category::factory()->create();

    $this->patchJson("/api/v1/categories/{$root->id}", ['parent_id' => $other->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('não deixa categoria ser pai de si mesma', function () {
    actingAsUser();
    $category = Category::factory()->create();

    $this->patchJson("/api/v1/categories/{$category->id}", ['parent_id' => $category->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('parent_id');
});

it('recusa excluir categoria com subcategorias', function () {
    actingAsUser();
    $root = Category::factory()->create();
    Category::factory()->create(['parent_id' => $root->id]);

    $this->deleteJson("/api/v1/categories/{$root->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'category_has_children');
});

it('exclui categoria sem subcategorias', function () {
    actingAsUser();
    $category = Category::factory()->create();

    $this->deleteJson("/api/v1/categories/{$category->id}")->assertNoContent();

    expect(Category::count())->toBe(0);
});

it('retorna 404 para categoria de outro usuário', function () {
    $other = User::factory()->create();
    $foreign = Category::factory()->create(['user_id' => $other->id]);

    actingAsUser();

    $this->getJson("/api/v1/categories/{$foreign->id}")->assertNotFound();
    $this->deleteJson("/api/v1/categories/{$foreign->id}")->assertNotFound();
});
