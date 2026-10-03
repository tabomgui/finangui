<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
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
 * uso pela Pluggy). Pede atualização só quando lastUpdatedAt existe e já tem
 * 20h ou mais. Falha do provedor ao pedir ou esperar a atualização
 * (indisponível ou recusado) não derruba o sync inteiro: loga e segue com o
 * último item que conseguiu buscar.
 */
final class ItemRefresher
{
    private const STALE_AFTER_HOURS = 20;

    private const MAX_WAIT_ATTEMPTS = 30;

    private const POLL_INTERVAL_SECONDS = 3;

    public function refreshAndWait(BankProvider $provider, BankConnection $connection, ProviderItem $item, CarbonImmutable $syncStartedAt): ProviderItem
    {
        $shouldRefresh = ! $item->isUpdating()
            && $item->lastUpdatedAt !== null
            && $item->lastUpdatedAt->lessThanOrEqualTo($syncStartedAt->subHours(self::STALE_AFTER_HOURS));

        if ($shouldRefresh) {
            try {
                $provider->refreshItem($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                $this->logWarning('Pluggy: falha ao pedir atualização do item; seguindo com o item já buscado.', $connection, $e);
            }
        }

        return $this->waitForUpdate($provider, $connection, $item);
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
