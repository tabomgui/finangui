<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ConnectionNotLinked;
use App\Domain\Banking\Models\BankConnection;

/**
 * O usuário concluiu a reconexão no widget (modo atualização, connect
 * token com `itemId`): a conexão volta a `active` e o erro anterior é
 * limpo. O primeiro sync depois da reconexão é responsabilidade de quem
 * chama esta action (ver App\Domain\Banking\Actions\QueueConnectionSync).
 *
 * Exige que a conexão já tenha passado pelo vínculo inicial (não
 * `pending_link`): reconectar faz sentido para uma conexão que já tem
 * contas vinculadas e precisa de novo login no banco, não para uma que
 * nunca terminou o primeiro vínculo.
 */
final class MarkReconnected
{
    public function handle(BankConnection $connection): BankConnection
    {
        if ($connection->status === ConnectionStatus::PendingLink) {
            throw new ConnectionNotLinked;
        }

        $connection->update([
            'status' => ConnectionStatus::Active,
            'last_error' => null,
        ]);

        return $connection;
    }
}
