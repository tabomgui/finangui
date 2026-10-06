<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankCredentialsInUse;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Models\User;

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
    public function handle(User $user): void
    {
        if (BankConnection::query()->where('user_id', $user->id)->exists()) {
            throw new BankCredentialsInUse;
        }

        BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->delete();
    }
}
