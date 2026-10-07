<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Models\BankConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Pede a atualização do item ao provedor e espera o banco terminar, para
 * App\Domain\Banking\Jobs\SyncConnection. Item já em UPDATING (de um refresh
 * anterior, deste sync ou de fora) só espera, sem pedir outro; sem
 * lastUpdatedAt (nunca atualizado) não há como saber se está velho, então
 * também só espera (nunca refresca "no escuro" — refreshItem tem limite de
 * uso pela Pluggy). Limiar de "velho" depende do gatilho do sync
 * (ver staleAfter()): um sync agendado pode esperar o item renovar sozinho
 * (a Pluggy atualiza 1×/dia); um disparado pelo usuário (manual, ou depois de
 * conectar/salvar credenciais) quer dados frescos na hora. Falha do provedor
 * ao pedir a atualização (indisponível ou recusado) não derruba o sync
 * inteiro: segue com o último item que conseguiu buscar, e o motivo vira um
 * aviso no histórico de sincronização (App\Domain\Banking\Models\BankSyncRun,
 * ver ItemRefreshOutcome::$warning) — uma falha ao esperar a atualização
 * (consultar o item de novo) só loga, sem aviso: o próprio refresh já foi
 * pedido com sucesso, e nada de novo a avisar além de seguir com o item que
 * já tínhamos.
 */
final class ItemRefresher
{
    private const SCHEDULED_STALE_AFTER_HOURS = 12;

    private const MANUAL_STALE_AFTER_MINUTES = 30;

    private const MAX_WAIT_ATTEMPTS = 30;

    private const POLL_INTERVAL_SECONDS = 3;

    public function refreshAndWait(BankProvider $provider, BankConnection $connection, ProviderItem $item, CarbonImmutable $syncStartedAt, SyncTrigger $trigger): ItemRefreshOutcome
    {
        $shouldRefresh = ! $item->isUpdating()
            && $item->lastUpdatedAt !== null
            && $item->lastUpdatedAt->lessThanOrEqualTo($this->staleThreshold($syncStartedAt, $trigger));

        $warning = null;

        if ($shouldRefresh) {
            try {
                $provider->refreshItem($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                $warning = 'Não foi possível pedir uma atualização ao banco; seguindo com os dados mais recentes já disponíveis.';
                $this->logWarning('Pluggy: falha ao pedir atualização do item; seguindo com o item já buscado.', $connection, $e);
            }
        }

        $item = $this->waitForUpdate($provider, $connection, $item);

        return new ItemRefreshOutcome($item, $shouldRefresh, $warning);
    }

    /**
     * `Scheduled`: 12h (a Pluggy já atualiza o item sozinha 1×/dia; não há
     * motivo para forçar antes disso). Qualquer gatilho por ação do usuário
     * (`Manual`, `Connect`, `Credentials`) quer dados frescos: 30min.
     */
    private function staleThreshold(CarbonImmutable $syncStartedAt, SyncTrigger $trigger): CarbonImmutable
    {
        return $trigger === SyncTrigger::Scheduled
            ? $syncStartedAt->subHours(self::SCHEDULED_STALE_AFTER_HOURS)
            : $syncStartedAt->subMinutes(self::MANUAL_STALE_AFTER_MINUTES);
    }

    private function waitForUpdate(BankProvider $provider, BankConnection $connection, ProviderItem $item): ProviderItem
    {
        for ($attempt = 0; $attempt < self::MAX_WAIT_ATTEMPTS && $item->isUpdating(); $attempt++) {
            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();

            try {
                $item = $provider->item($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                $this->logWarning('Pluggy: falha ao consultar o item enquanto esperava a atualização; seguindo com o último item buscado.', $connection, $e);

                break;
            }
        }

        return $item;
    }

    private function logWarning(string $message, BankConnection $connection, Throwable $e): void
    {
        Log::warning($message, [
            'connection_id' => $connection->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
