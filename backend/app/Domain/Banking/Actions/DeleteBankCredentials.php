<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankCredentialsInUse;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;

/**
 * Remove a credencial da Pluggy do usuário. Bloqueado (BankCredentialsInUse)
 * enquanto houver conexões bancárias (qualquer status): elas dependem da
 * credencial para sincronizar e para desconectar no provedor (ver
 * App\Domain\Banking\Actions\DisconnectConnection) — o usuário precisa
 * desconectar os bancos antes. Sem credencial cadastrada, apaga 0 linhas
 * (idempotente, nunca um erro).
 */
final class DeleteBankCredentials
{
    /**
     * @throws BankCredentialsInUse
     */
    public function handle(User $user): void
    {
        if (BankConnection::query()->where('user_id', $user->id)->exists()) {
            throw new BankCredentialsInUse;
        }

        $current = BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->first();

        if ($current !== null) {
            // Melhor esforço: client_id ilegível (ex.: APP_KEY trocada) não
            // impede remover a linha — só não há como montar a chave de
            // cache da API key para limpar, e nada mais a fazer sobre isso.
            try {
                Cache::forget(PluggyProvider::apiKeyCacheKeyFor($user->id, $current->client_id));
            } catch (DecryptException) {
                // Nada a limpar: sem o client_id em claro não existe chave de cache a montar.
            }
        }

        BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->delete();
    }
}
