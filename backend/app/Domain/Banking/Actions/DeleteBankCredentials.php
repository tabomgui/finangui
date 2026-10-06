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
use Illuminate\Support\Facades\DB;

/**
 * Remove a credencial da Pluggy do usuário. Bloqueado (BankCredentialsInUse)
 * só quando o usuário tem conexões bancárias cujo `credential_fingerprint`
 * bate com o da credencial atual (ver
 * App\Domain\Banking\Models\BankCredential::fingerprint()/readableClientId()
 * e App\Domain\Banking\Models\BankConnection, mesma regra de
 * App\Domain\Banking\Actions\SaveBankCredentials): elas dependem da
 * credencial para sincronizar e para desconectar no provedor (ver
 * App\Domain\Banking\Actions\DisconnectConnection) — o usuário precisa
 * desconectar os bancos antes. Conexão com fingerprint null ou de outra
 * conta nunca bloqueia; sem credencial cadastrada (currentClientId null),
 * nenhuma checagem se aplica. Mesma trava (DB::transaction + User
 * lockForUpdate) de SaveBankCredentials, pelo mesmo motivo: serializar duas
 * gravações concorrentes e refazer a checagem sob a trava.
 */
final class DeleteBankCredentials
{
    /**
     * @throws BankCredentialsInUse
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $current = BankCredential::query()
                ->where('user_id', $user->id)
                ->where('provider', BankProviderName::Pluggy)
                ->first();

            $currentClientId = BankCredential::readableClientId($current);

            if ($currentClientId !== null && $this->hasConnectionsFor($user, $currentClientId)) {
                throw new BankCredentialsInUse;
            }

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
        });
    }

    /**
     * Só conta como "em uso" a conexão cujo credential_fingerprint bate com
     * o fingerprint de $clientId (a conta atual) — null ou de outra conta
     * nunca bloqueia (ver App\Domain\Banking\Models\BankConnection).
     */
    private function hasConnectionsFor(User $user, string $clientId): bool
    {
        return BankConnection::query()
            ->where('user_id', $user->id)
            ->where('credential_fingerprint', BankCredential::fingerprint($clientId))
            ->exists();
    }
}
