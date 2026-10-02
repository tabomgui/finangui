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

it('ignora linhas com formato inválido e importa as válidas', function () {
    $user = User::factory()->create(['email' => 'gui@example.com']);

    $path = tempnam(sys_get_temp_dir(), 'legacy');
    expect($path)->not->toBeFalse();
    file_put_contents($path, implode("\n", [
        "id\tname\ttype\tcolor\tparent_id",
        '7',
        "abc\tInválida\texpense\t#f97316\tNULL",
        "1\tAlimentação\texpense\t#f97316\tNULL",
    ])."\n");

    $this->artisan('legacy:import-categories', [
        'file' => $path,
        'email' => 'gui@example.com',
    ])->assertSuccessful();

    unlink($path);

    $categories = Category::query()->where('user_id', $user->id)->get()->keyBy('name');

    expect($categories)->toHaveCount(1)
        ->and($categories->has('NULL'))->toBeFalse()
        ->and($categories->has('abc'))->toBeFalse()
        ->and($categories->has('Inválida'))->toBeFalse()
        ->and($categories->has('Alimentação'))->toBeTrue();
});

it('falha para usuário inexistente ou arquivo ilegível', function () {
    User::factory()->create(['email' => 'gui@example.com']);

    $this->artisan('legacy:import-categories', ['file' => base_path('tests/Fixtures/legacy-categories.tsv'), 'email' => 'nao@existe.com'])
        ->assertFailed();
    $this->artisan('legacy:import-categories', ['file' => '/nao/existe.tsv', 'email' => 'gui@example.com'])
        ->assertFailed();
});
