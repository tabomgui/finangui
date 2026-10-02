<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Users\Actions\CreateUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        if (Auth::check()) {
            return $this->link($googleUser);
        }

        // O Google pode voltar sem email (ex.: conta sem email verificado). Sem
        // email não há como localizar/criar o usuário, então tratamos como falha.
        $email = $this->normalizeEmail($googleUser->getEmail());
        if ($email === null) {
            return redirect()->away($this->frontend('/login?error=google_failed'));
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user === null) {
            if (! config('finangui.registration_enabled')) {
                return redirect()->away($this->frontend('/login?error=registration_closed'));
            }

            $user = $createUser->handle(
                name: (string) $googleUser->getName(),
                email: $email,
                googleId: (string) $googleUser->getId(),
                avatar: $googleUser->getAvatar(),
            );
        } else {
            $user->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar' => $user->avatar ?? $googleUser->getAvatar(),
            ])->save();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away($this->frontend('/'));
    }

    private function link(GoogleUser $googleUser): RedirectResponse
    {
        /** @var User $current */
        $current = Auth::user();

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

    private function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
