<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Models\BankConnection;

/**
 * O usuário concluiu a reconexão no widget (modo atualização, connect
 * token com `itemId`): a conexão volta a `active` e o erro anterior é
 * limpo. O primeiro sync depois da reconexão é responsabilidade de quem
 * chama esta action (ver App\Domain\Banking\Actions\QueueConnectionSync).
 */
final class MarkReconnected
{
    public function handle(BankConnection $connection): BankConnection
    {
        $connection->update([
            'status' => ConnectionStatus::Active,
            'last_error' => null,
        ]);

        return $connection;
    }
}
