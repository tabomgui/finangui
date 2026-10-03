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
 * (`connection_id`/`external_id`/`provider_balance`/`provider_synced_at`
 * nulos), com todo o histórico de transações intacto.
 *
 * Esta ação roda mesmo com o provedor desligado (sem credenciais): quem
 * desconecta pode estar limpando uma conexão de antes das credenciais
 * serem removidas — pula a chamada ao provedor nesse caso, em vez de
 * tentar (e logar) uma falha óbvia.
 */
final class DisconnectConnection
{
    public function __construct(private readonly BankProvider $provider) {}

    public function handle(BankConnection $connection): void
    {
        if ($this->provider->enabled()) {
            try {
                $this->provider->deleteItem($connection->external_id);
            } catch (Throwable $e) {
                Log::warning('Pluggy: falha ao excluir o item ao desconectar; a conexão local é excluída de todo modo.', [
                    'connection_id' => $connection->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($connection): void {
            $connection->accounts()->update([
                'connection_id' => null,
                'external_id' => null,
                'provider_balance' => null,
                'provider_synced_at' => null,
            ]);
            $connection->delete();
        });
    }
}
