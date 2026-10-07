<?php

namespace App\Domain\Banking\Support;

use App\Domain\Banking\Data\ProviderItem;

/**
 * Resultado de App\Domain\Banking\Support\ItemRefresher::refreshAndWait() —
 * além do item (como antes), o que App\Domain\Banking\Jobs\SyncConnection
 * precisa para alimentar o histórico de sincronização
 * (App\Domain\Banking\Models\BankSyncRun): se um refresh foi de fato pedido
 * (`refresh_requested`) e um aviso voltado ao usuário quando o pedido falhou
 * (`warning`, null quando não houve nada a avisar — o sync segue com o que a
 * Pluggy já tinha, nunca falha por isso).
 */
final readonly class ItemRefreshOutcome
{
    public function __construct(
        public ProviderItem $item,
        public bool $refreshRequested,
        public ?string $warning = null,
    ) {}
}
