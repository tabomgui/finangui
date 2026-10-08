<?php

namespace App\Domain\Banking\Data;

use Carbon\CarbonImmutable;

/**
 * Item (conexão) do provedor. `fromArray()` fica no provedor concreto
 * (PluggyProvider), não aqui — este DTO só carrega os dados já traduzidos.
 */
final readonly class ProviderItem
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $clientUserId,
        public ?CarbonImmutable $lastUpdatedAt,
        public ?string $institutionName,
        public ?string $institutionLogoUrl,
        public ?string $errorMessage,
    ) {}

    public function needsReauth(): bool
    {
        return in_array($this->status, ['LOGIN_ERROR', 'WAITING_USER_INPUT'], true);
    }

    public function isUpdating(): bool
    {
        return $this->status === 'UPDATING';
    }

    /**
     * Item de um conector que a Pluggy nunca consegue atualizar sob pedido
     * (`PATCH /items/{id}` sempre recusa — ver
     * App\Domain\Banking\Support\ItemRefresher): detectado só pelo nome do
     * conector — hoje, "MeuPluggy", o agregador Open Finance deles mesmos.
     * Deliberadamente estrito: um sinal mais genérico (ex.: isOpenFinance +
     * oauth do payload do conector) pegaria falsos positivos de outros
     * conectores OAuth que não têm essa limitação — melhor deixar passar um
     * conector novo com o mesmo problema (cai no caminho de erro específico
     * em ItemRefresher na primeira tentativa de refresh) do que bloquear o
     * refresh de alguém que não devia.
     */
    public function refreshUnsupported(): bool
    {
        return $this->institutionName !== null && mb_strtolower($this->institutionName) === 'meupluggy';
    }
}
