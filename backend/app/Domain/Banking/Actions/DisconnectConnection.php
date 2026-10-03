<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Desconecta: tenta excluir o item no provedor (melhor esforço — uma falha
 * do lado do Pluggy não pode impedir a desconexão local, só fica no log) e
 * exclui a conexão; as contas vinculadas voltam a ser manuais
 * (`connection_id`/`external_id` nulos), com todo o histórico de
 * transações intacto.
 */
final class DisconnectConnection
{
    public function __construct(private readonly BankProvider $provider) {}

    public function handle(BankConnection $connection): void
    {
        try {
            $this->provider->deleteItem($connection->external_id);
        } catch (Throwable $e) {
            Log::warning('Pluggy: falha ao excluir o item ao desconectar; a conexão local é excluída de todo modo.', [
                'connection_id' => $connection->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($connection): void {
            $connection->accounts()->update(['connection_id' => null, 'external_id' => null]);
            $connection->delete();
        });
    }
}
