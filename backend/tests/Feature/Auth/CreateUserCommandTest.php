<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('cria usuário com categorias padrão', function () {
    $this->artisan('user:create', ['email' => 'gui@example.com', '--name' => 'Gui'])
        ->expectsQuestion('Senha', 'password123')
        ->assertSuccessful();

    $user = User::where('email', 'gui@example.com')->firstOrFail();

    expect($user->name)->toBe('Gui')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(Category::query()->where('user_id', $user->id)->count())->toBeGreaterThan(10);
});

it('recusa email já cadastrado', function () {
    User::factory()->create(['email' => 'gui@example.com']);

    $this->artisan('user:create', ['email' => 'gui@example.com'])
        ->assertFailed();
});

it('recusa senha curta', function () {
    $this->artisan('user:create', ['email' => 'gui@example.com'])
        ->expectsQuestion('Senha', 'curta')
        ->assertFailed();

    expect(User::count())->toBe(0);
});
