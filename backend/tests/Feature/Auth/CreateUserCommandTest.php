<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('cria usuário com categorias padrão', function () {
    $this->artisan('user:create', ['email' => 'gui@example.com', '--name' => 'Gui'])
        ->expectsQuestion('Senha', 'password123')
        ->expectsQuestion('Confirme a senha', 'password123')
        ->assertSuccessful();

    $user = User::where('email', 'gui@example.com')->firstOrFail();

    expect($user->name)->toBe('Gui')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Category::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBeGreaterThan(10);
});

it('recusa email já cadastrado', function () {
    User::factory()->create(['email' => 'gui@example.com']);

    $this->artisan('user:create', ['email' => 'gui@example.com'])
        ->assertFailed();
});

it('recusa senha curta', function () {
    $this->artisan('user:create', ['email' => 'gui@example.com'])
        ->expectsQuestion('Senha', 'curta')
        ->expectsQuestion('Confirme a senha', 'curta')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('recusa quando a confirmação de senha não bate', function () {
    $this->artisan('user:create', ['email' => 'gui@example.com'])
        ->expectsQuestion('Senha', 'password123')
        ->expectsQuestion('Confirme a senha', 'outrasenha123')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('normaliza o email para minúsculas e sem espaços', function () {
    $this->artisan('user:create', ['email' => ' Gui@Example.com '])
        ->expectsQuestion('Senha', 'password123')
        ->expectsQuestion('Confirme a senha', 'password123')
        ->assertSuccessful();

    expect(User::where('email', 'gui@example.com')->exists())->toBeTrue();
});
