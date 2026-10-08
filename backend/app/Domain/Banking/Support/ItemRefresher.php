<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Models\BankConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 *
 * `settings.refresh_unsupported` (App\Domain\Banking\Models\BankConnection):
 * item de um conector que a Pluggy nunca deixa atualizar sob pedido (ex.:
 * MeuPluggy — `PATCH /items/{id}` sempre responde 400 "item cant be
 * updated"). Detectado de antemão por ProviderItem::refreshUnsupported()
 * (só pelo nome do conector, deliberadamente estrito) ou, na primeira vez
 * que o pedido de refresh de fato falha com esse erro específico, gravado
 * na conexão junto com `settings.refresh_unsupported_connector` (o nome do
 * conector no momento da marcação) — dali em diante nenhum sync seguinte
 * volta a pedir (nem avisa: já sabemos que é permanente, não uma falha
 * transitória). Limpa sozinha quando o conector do item muda desde então
 * (ver clearIfConnectorChanged()) — uma troca por baixo pode deixar de ter
 * essa limitação; guardado separado de `institution_name` (mostrado na UI,
 * nunca atualizado depois da criação da conexão — ver
 * App\Domain\Banking\Actions\CreateConnection) para não mexer nele por um
 * motivo que não é da UI. Limpa também "de fora" em
 * App\Domain\Banking\Actions\MarkReconnected (reconexão) e
 * App\Domain\Banking\Actions\SaveBankCredentials (credenciais novas), que
 * não passam por aqui. Item já `UPDATING` continua só esperando, como
 * sempre: a flag só evita o PEDIDO, nunca a espera por uma atualização em
 * andamento.
 */
final class ItemRefresher
{
    private const SCHEDULED_STALE_AFTER_HOURS = 12;

    private const MANUAL_STALE_AFTER_MINUTES = 30;

    private const MAX_WAIT_ATTEMPTS = 30;

    private const POLL_INTERVAL_SECONDS = 3;

    /**
     * Regex exata (o provedor nomeia o conector na própria mensagem, "cant"
     * sem apóstrofo na maioria das vezes observadas, mas "can't" também
     * aceito) — casa sozinha, sem precisar do nome do conector vindo do
     * item. Comparação sem se importar com caixa (flag `i`).
     *
     * Deliberadamente sem um segundo caminho pela substring genérica "cant
     * be updated" combinada com ProviderItem::refreshUnsupported(): quando
     * o item já é MeuPluggy pelo nome, refreshAndWait() nunca chega a pedir
     * o refresh (retorna antes, no topo) — então, dentro deste catch,
     * $item->refreshUnsupported() é sempre false, e essa combinação nunca
     * aconteceria de verdade.
     */
    private const UNSUPPORTED_EXACT_PATTERN = '/meupluggy\s+item\s+can\'?t\s+be\s+updated/i';

    public function refreshAndWait(BankProvider $provider, BankConnection $connection, ProviderItem $item, CarbonImmutable $syncStartedAt, SyncTrigger $trigger): ItemRefreshOutcome
    {
        $this->clearIfConnectorChanged($connection, $item);

        if ($this->isRefreshUnsupported($connection) || $item->refreshUnsupported()) {
            $this->markRefreshUnsupported($connection, $item);

            $item = $this->waitForUpdate($provider, $connection, $item);

            return new ItemRefreshOutcome($item, false, null);
        }

        $shouldRefresh = ! $item->isUpdating()
            && $item->lastUpdatedAt !== null
            && $item->lastUpdatedAt->lessThanOrEqualTo($this->staleThreshold($syncStartedAt, $trigger));

        $warning = null;

        if ($shouldRefresh) {
            try {
                $provider->refreshItem($connection->external_id);
            } catch (ProviderUnavailable|ProviderRequestFailed $e) {
                if ($e instanceof ProviderRequestFailed && $this->isRefreshUnsupportedError($e)) {
                    // Erro permanente, não transitório: nunca mais pedimos a
                    // partir de agora, e este pedido que acabou de falhar
                    // não conta como "pedimos atualização" nem gera aviso.
                    $this->markRefreshUnsupported($connection, $item);
                    $shouldRefresh = false;
                } else {
                    $warning = 'Não foi possível pedir uma atualização ao banco; seguindo com os dados mais recentes já disponíveis.';
                    $this->logWarning('Pluggy: falha ao pedir atualização do item; seguindo com o item já buscado.', $connection, $e);
                }
            }
        }

        $item = $this->waitForUpdate($provider, $connection, $item);

        return new ItemRefreshOutcome($item, $shouldRefresh, $warning);
    }

    private function isRefreshUnsupported(BankConnection $connection): bool
    {
        return ($connection->settings['refresh_unsupported'] ?? false) === true;
    }

    /**
     * O erro bate quando a mensagem nomeia "meupluggy item can('')t be
     * updated" — ver UNSUPPORTED_EXACT_PATTERN.
     */
    private function isRefreshUnsupportedError(ProviderRequestFailed $e): bool
    {
        if ($e->status !== 400 || $e->providerMessage === null) {
            return false;
        }

        return preg_match(self::UNSUPPORTED_EXACT_PATTERN, $e->providerMessage) === 1;
    }

    private function markRefreshUnsupported(BankConnection $connection, ProviderItem $item): void
    {
        if ($this->isRefreshUnsupported($connection)) {
            return;
        }

        DB::transaction(function () use ($connection, $item) {
            $locked = BankConnection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($locked === null || ($locked->settings['refresh_unsupported'] ?? false) === true) {
                return;
            }

            $settings = $locked->settings ?? [];
            $settings['refresh_unsupported'] = true;
            $settings['refresh_unsupported_connector'] = $item->institutionName;
            $locked->update(['settings' => $settings]);
        });

        // Mantém a instância que App\Domain\Banking\Jobs\SyncConnection já
        // tem em mãos consistente com o que acabou de ser gravado, para o
        // resto desta mesma execução não pedir refresh de novo nem precisar
        // reler a conexão do banco.
        $connection->settings = [
            ...($connection->settings ?? []),
            'refresh_unsupported' => true,
            'refresh_unsupported_connector' => $item->institutionName,
        ];
    }

    /**
     * O conector mudou desde que a flag foi gravada (settings.refresh_unsupported_connector,
     * de markRefreshUnsupported()): a limitação pode não valer mais, então
     * limpa as duas chaves para o restante deste refreshAndWait()
     * reconsiderar do zero (de antemão, ou pedindo de verdade). Sem o
     * marcador gravado (flag de antes desta coluna existir, ou da primeira
     * vez que o item não tinha institutionName algum), não há como saber se
     * mudou — a flag fica como está, nunca limpa "no escuro". Nunca toca
     * em `institution_name` (mostrado na UI, assunto de outra decisão —
     * ver a classe) nem em nada quando a flag não está gravada.
     */
    private function clearIfConnectorChanged(BankConnection $connection, ProviderItem $item): void
    {
        if (! $this->isRefreshUnsupported($connection)) {
            return;
        }

        $flaggedConnector = $connection->settings['refresh_unsupported_connector'] ?? null;

        if ($flaggedConnector === null || $flaggedConnector === $item->institutionName) {
            return;
        }

        DB::transaction(function () use ($connection) {
            $locked = BankConnection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($locked === null || ($locked->settings['refresh_unsupported'] ?? false) !== true) {
                return;
            }

            $settings = $locked->settings ?? [];
            unset($settings['refresh_unsupported'], $settings['refresh_unsupported_connector']);
            $locked->update(['settings' => $settings]);
        });

        $settings = $connection->settings ?? [];
        unset($settings['refresh_unsupported'], $settings['refresh_unsupported_connector']);
        $connection->settings = $settings;
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
