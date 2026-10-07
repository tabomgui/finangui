<?php

namespace App\Domain\Banking\Enums;

/**
 * O que disparou uma execução de App\Domain\Banking\Jobs\SyncConnection,
 * registrado em App\Domain\Banking\Models\BankSyncRun. Vem sempre de quem
 * despacha o job (nunca decidido dentro dele):
 *
 * - Scheduled: App\Domain\Banking\Jobs\SyncStaleConnections (agendador, a
 *   cada 6h);
 * - Manual: botão "Sincronizar agora" (`POST /bank-connections/{id}/sync`,
 *   App\Http\Controllers\Api\V1\BankConnectionController::sync());
 * - Connect: fluxo do widget da Pluggy — vínculo inicial de contas
 *   (App\Domain\Banking\Actions\LinkAccounts) ou reconexão
 *   (App\Domain\Banking\Actions\MarkReconnected) — e a própria conexão nova
 *   (App\Domain\Banking\Actions\CreateConnection não dispara sync nenhum
 *   antes do vínculo, mas conceitualmente é o mesmo fluxo);
 * - Credentials: resync automático depois de salvar credenciais
 *   (App\Domain\Banking\Actions\SaveBankCredentials), para conexões que
 *   estavam em erro por falta/erro de credencial.
 */
enum SyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Connect = 'connect';
    case Credentials = 'credentials';
}
