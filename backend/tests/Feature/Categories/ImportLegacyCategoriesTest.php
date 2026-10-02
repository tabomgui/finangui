<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;

it('importa categorias legadas achatando para um nível', function () {
    $user = User::factory()->create(['email' => 'gui@example.com']);

    $this->artisan('legacy:import-categories', [
        'file' => base_path('tests/Fixtures/legacy-categories.tsv'),
        'email' => 'gui@example.com',
    ])->assertSuccessful();

    $categories = Category::query()->where('user_id', $user->id)->get()->keyBy('name');

    expect($categories)->toHaveCount(5)
        ->and($categories['Mercado']->parent_id)->toBe($categories['Alimentação']->id)
        ->and($categories['Hortifruti']->parent_id)->toBe($categories['Alimentação']->id)
        ->and($categories['Salário']->kind->value)->toBe('income')
        ->and($categories['Tesouro Direto']->is_transfer)->toBeTrue()
        ->and($categories['Tesouro Direto']->color)->toBeNull()
        ->and($categories->has('Órfã'))->toBeFalse();
});

it('é idempotente', function () {
    $user = User::factory()->create(['email' => 'gui@example.com']);
    $args = ['file' => base_path('tests/Fixtures/legacy-categories.tsv'), 'email' => 'gui@example.com'];

    $this->artisan('legacy:import-categories', $args)->assertSuccessful();
    $this->artisan('legacy:import-categories', $args)->assertSuccessful();

    expect(Category::query()->where('user_id', $user->id)->count())->toBe(5);
});

it('falha para usuário inexistente ou arquivo ilegível', function () {
    User::factory()->create(['email' => 'gui@example.com']);

    $this->artisan('legacy:import-categories', ['file' => base_path('tests/Fixtures/legacy-categories.tsv'), 'email' => 'nao@existe.com'])
        ->assertFailed();
    $this->artisan('legacy:import-categories', ['file' => '/nao/existe.tsv', 'email' => 'gui@example.com'])
        ->assertFailed();
});
