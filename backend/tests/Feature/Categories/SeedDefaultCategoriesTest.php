<?php

use App\Domain\Categories\Actions\SeedDefaultCategories;
use App\Domain\Categories\Models\Category;
use App\Models\User;

it('cria as categorias padrão para o usuário', function () {
    $user = User::factory()->create();

    app(SeedDefaultCategories::class)->handle($user);

    $categories = Category::query()->withoutGlobalScopes()->where('user_id', $user->id)->get();

    expect($categories->firstWhere('name', 'Alimentação')->kind->value)->toBe('expense')
        ->and($categories->firstWhere('name', 'Salário')->kind->value)->toBe('income')
        ->and($categories->firstWhere('name', 'Pagamento de fatura')->is_transfer)->toBeTrue()
        ->and($categories->firstWhere('name', 'Mercado')->parent_id)
        ->toBe($categories->firstWhere('name', 'Alimentação')->id);
});

it('é idempotente', function () {
    $user = User::factory()->create();
    $seed = app(SeedDefaultCategories::class);

    $seed->handle($user);
    $count = Category::query()->withoutGlobalScopes()->where('user_id', $user->id)->count();
    $seed->handle($user);

    expect(Category::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe($count);
});
