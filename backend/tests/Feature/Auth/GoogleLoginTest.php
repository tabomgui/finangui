<?php

use App\Domain\Categories\Models\Category;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGoogleUser(?string $email, string $id = 'google-123'): void
{
    $googleUser = Mockery::mock(SocialiteUser::class);
    $googleUser->shouldReceive('getId')->andReturn($id);
    $googleUser->shouldReceive('getEmail')->andReturn($email);
    $googleUser->shouldReceive('getName')->andReturn('Gui');
    $googleUser->shouldReceive('getAvatar')->andReturn('https://avatar.test/gui.png');

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function frontend(string $path): string
{
    return rtrim((string) config('app.frontend_url'), '/').$path;
}

beforeEach(function () {
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);
});

it('volta para o login quando o Google não está configurado', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get('/api/auth/google/redirect')->assertRedirect(frontend('/login'));
});

it('redireciona para o Google quando configurado', function () {
    config(['services.google.redirect' => 'http://localhost/api/auth/google/callback']);

    $this->get('/api/auth/google/redirect')->assertRedirectContains('accounts.google.com');
});

it('recusa conta nova com cadastro fechado', function () {
    config(['finangui.registration_enabled' => false]);
    fakeGoogleUser('novo@gmail.com');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/login?error=registration_closed'));

    expect(User::count())->toBe(0);
});

it('cria conta com categorias padrão quando o cadastro está aberto', function () {
    config(['finangui.registration_enabled' => true]);
    fakeGoogleUser('novo@gmail.com');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/'));

    $user = User::where('email', 'novo@gmail.com')->firstOrFail();
    expect($user->google_id)->toBe('google-123')
        ->and($user->password)->toBeNull()
        ->and(Category::query()->where('user_id', $user->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('loga conta existente pelo email e vincula o google_id', function () {
    $user = User::factory()->create(['email' => 'gui@gmail.com', 'google_id' => null, 'avatar' => null]);
    fakeGoogleUser('gui@gmail.com');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/'));

    expect($user->fresh()->google_id)->toBe('google-123')
        ->and($user->fresh()->avatar)->toBe('https://avatar.test/gui.png')
        ->and(User::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('vincula a conta Google ao usuário já logado', function () {
    $user = actingAsUser(['email' => 'gui@example.com']);
    fakeGoogleUser('outro-email@gmail.com');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/configuracoes?google=linked'));

    expect($user->fresh()->google_id)->toBe('google-123');
});

it('não vincula conta Google que já pertence a outro usuário', function () {
    User::factory()->create(['google_id' => 'google-123']);
    $user = actingAsUser();
    fakeGoogleUser('x@gmail.com');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/configuracoes?google=taken'));

    expect($user->fresh()->google_id)->toBeNull();
});

it('trata state inválido como falha de login', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/login?error=google_failed'));
});

it('loga conta existente ignorando maiúsculas/minúsculas e espaços no email do Google', function () {
    $user = User::factory()->create(['email' => 'gui@gmail.com', 'google_id' => null, 'avatar' => null]);
    fakeGoogleUser(' Gui@Gmail.com ');

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/'));

    expect(User::count())->toBe(1)
        ->and($user->fresh()->google_id)->toBe('google-123');
    $this->assertAuthenticatedAs($user);
});

it('redireciona para login com erro quando o Google não retorna email', function () {
    config(['finangui.registration_enabled' => true]);
    fakeGoogleUser(null);

    $this->get('/api/auth/google/callback')->assertRedirect(frontend('/login?error=google_failed'));

    expect(User::count())->toBe(0);
});
