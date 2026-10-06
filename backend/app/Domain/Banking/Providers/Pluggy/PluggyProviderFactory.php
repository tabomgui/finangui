<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Models\BankCredential;
use App\Models\User;

/**
 * Lê a credencial do próprio usuário (nunca a de outro: withoutGlobalScopes()
 * + where('user_id', ...) explícito, em vez de confiar no global scope de
 * BelongsToUser bater com o usuário autenticado no momento da chamada) e
 * monta um PluggyProvider com ela. Sem credencial verificada, o usuário
 * ainda não "ligou" a integração — ver App\Domain\Banking\Errors\BankingDisabled.
 */
final class PluggyProviderFactory implements BankProviderFactory
{
    public function for(User $user): BankProvider
    {
        $credential = BankCredential::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->whereNotNull('verified_at')
            ->first();

        if ($credential === null) {
            throw new BankingDisabled;
        }

        /** @var string $baseUrl */
        $baseUrl = config('services.pluggy.base_url');

        return new PluggyProvider($credential->client_id, $credential->client_secret, $baseUrl, $user->id);
    }
}
