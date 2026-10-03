<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Desconecta: tenta excluir o item no provedor (melhor esforço — uma falha
 * do lado do Pluggy não pode impedir a desconexão local, só fica no log) e
 * exclui a conexão; as contas vinculadas voltam a ser manuais
 * (`connection_id`/`external_id`/`provider_balance`/`provider_synced_at`/
 * `provider_sync_from`/`provider_opening_set_at`/`provider_history_synced_at`
 * nulos), com todo o histórico de transações intacto.
 *
 * As transações que o banco trouxe (`source = pluggy`) e as faturas que ele
 * criou também perdem o `external_id`: sem isso, religar a mesma conta (ou
 * uma nova) a um banco mais tarde nunca reconheceria essas linhas como "já
 * existe" nem como candidata a adoção (ver
 * App\Domain\Imports\Support\IngestionPlanner::adoptionPool(), que só adota
 * lançamento sem external_id ou — num lote pluggy — de outro formato), e o
 * próximo sync duplicaria tudo de novo.
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
            $accountIds = $connection->accounts()->pluck('id');

            Transaction::query()
                ->whereIn('account_id', $accountIds)
                ->where('source', TransactionSource::Pluggy->value)
                ->update(['external_id' => null]);

            CardStatement::query()
                ->whereIn('account_id', $accountIds)
                ->update(['external_id' => null]);

            $connection->accounts()->update([
                'connection_id' => null,
                'external_id' => null,
                'provider_balance' => null,
                'provider_synced_at' => null,
                'provider_sync_from' => null,
                'provider_opening_set_at' => null,
                'provider_history_synced_at' => null,
            ]);
            $connection->delete();
        });
    }
}
