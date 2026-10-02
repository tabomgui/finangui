<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Users\Actions\CreateUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleCredentials;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as GoogleUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as BaseRedirectResponse;

/**
 * OAuth do Google. Fica no grupo `web` (sessão) porque o callback chega direto
 * do Google. Sem ->stateless(): o parâmetro `state` é validado contra a sessão
 * (proteção contra login CSRF).
 */
final class GoogleController extends Controller
{
    public function redirect(): BaseRedirectResponse
    {
        if (! GoogleCredentials::configured()) {
            return redirect()->away($this->frontend('/login'));
        }

        // O contrato de Socialite\Contracts\Provider::redirect() devolve
        // Symfony\...\RedirectResponse|Illuminate\Http\RedirectResponse; a implementação
        // concreta devolve o tipo Illuminate, mas tipamos pelo tipo base comum aos dois ramos.
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, CreateUser $createUser): RedirectResponse
    {
        // O Google manda `error` na query string (ex.: `access_denied`, usuário
        // cancelou) sem nunca chegar a emitir um `code`. Tratamos antes de tentar
        // trocar o código por um usuário.
        if ($request->filled('error')) {
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException) {
            // InvalidStateException: `state` não bate com a sessão (CSRF/sessão expirada).
            // GuzzleException: falha de rede/HTTP ao trocar o código com o Google.
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        if (Auth::check()) {
            return $this->link($googleUser);
        }

        $userByGoogleId = User::where('google_id', $googleUser->getId())->first();

        if ($userByGoogleId !== null) {
            // O `sub` do Google é estável e esta conta já está vinculada: loga
            // direto, sem repetir as checagens de email (que só valem para
            // localizar/criar conta pelo email).
            $userByGoogleId->forceFill([
                'avatar' => $userByGoogleId->avatar ?? $googleUser->getAvatar(),
            ])->save();

            return $this->loginAndRedirect($request, $userByGoogleId);
        }

        $email = $this->normalizeEmail($googleUser->getEmail());
        if ($email === null) {
            // O Google pode voltar sem email (ex.: conta sem email verificado). Sem
            // email não há como localizar/criar o usuário, então tratamos como falha.
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        if (! $this->googleEmailVerified($googleUser)) {
            // Sem o email confirmado pelo Google não dá para confiar nele para
            // localizar/criar a conta: qualquer um pode criar um Google Account
            // com um email de terceiros não verificado.
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        $userByEmail = User::where('email', $email)->first();

        if ($userByEmail !== null) {
            if ($userByEmail->google_id !== null && $userByEmail->google_id !== $googleUser->getId()) {
                // O email já está vinculado a outra conta Google: não sobrescreve.
                return redirect()->away($this->frontend('/login?error=google_conflict'));
            }

            if ($userByEmail->password !== null && $userByEmail->email_verified_at === null) {
                // Conta com senha cujo email nunca foi confirmado: vincular
                // automaticamente aqui abriria uma hijacking — alguém que registre
                // esse email no Google tomaria a conta. Precisa logar com senha e
                // vincular pelas Configurações.
                return redirect()->away($this->frontend('/login?error=google_link_requires_password'));
            }

            $userByEmail->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar' => $userByEmail->avatar ?? $googleUser->getAvatar(),
            ])->save();

            return $this->loginAndRedirect($request, $userByEmail);
        }

        if (! config('finangui.registration_enabled')) {
            return redirect()->away($this->frontend('/login?error=registration_closed'));
        }

        $user = $createUser->handle(
            name: (string) $googleUser->getName(),
            email: $email,
            googleId: (string) $googleUser->getId(),
            avatar: $googleUser->getAvatar(),
            emailVerified: true,
        );

        return $this->loginAndRedirect($request, $user);
    }

    private function loginAndRedirect(Request $request, User $user): RedirectResponse
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away($this->frontend('/'));
    }

    private function link(GoogleUser $googleUser): RedirectResponse
    {
        /** @var User $current */
        $current = Auth::user();

        if ($current->google_id !== null && $current->google_id !== $googleUser->getId()) {
            return redirect()->away($this->frontend('/configuracoes?google=already_linked'));
        }

        $taken = User::where('google_id', $googleUser->getId())->whereKeyNot($current->id)->exists();
        if ($taken) {
            return redirect()->away($this->frontend('/configuracoes?google=taken'));
        }

        $current->forceFill([
            'google_id' => $googleUser->getId(),
            'avatar' => $current->avatar ?? $googleUser->getAvatar(),
        ])->save();

        return redirect()->away($this->frontend('/configuracoes?google=linked'));
    }

    /**
     * Normaliza o email do Google antes de qualquer lookup: o mutator de
     * `User::email` só normaliza em escrita, então uma busca direta por
     * `where('email', ...)` com o valor bruto do Google não encontraria uma
     * conta existente se vier com maiúsculas/espaços diferentes.
     */
    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_strtolower($email);
    }

    /**
     * `getRaw()` não faz parte do contrato `Socialite\Contracts\User`, só da
     * implementação concreta — por isso o `instanceof`. O Google manda
     * `email_verified` como bool ou, às vezes, como string ("true"/"false").
     */
    private function googleEmailVerified(GoogleUser $googleUser): bool
    {
        $raw = $googleUser instanceof AbstractUser ? $googleUser->getRaw() : [];

        /** @var mixed $emailVerified */
        $emailVerified = $raw['email_verified'] ?? false;

        return filter_var($emailVerified, FILTER_VALIDATE_BOOLEAN);
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
