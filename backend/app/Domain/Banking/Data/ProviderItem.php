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
}
