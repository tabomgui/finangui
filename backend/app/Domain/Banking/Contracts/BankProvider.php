<?php

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use Carbon\CarbonImmutable;

/**
 * Isola o provedor de open finance (Pluggy em produção, fake em teste) do
 * resto do domínio Banking.
 */
interface BankProvider
{
    /** Falso quando as credenciais do provedor não estão configuradas. */
    public function enabled(): bool;

    /** Token curto para o widget; com $itemId abre em modo atualização. */
    public function connectToken(string $clientUserId, ?string $itemId = null): string;

    public function item(string $itemId): ProviderItem;

    /** Pede atualização do item ao banco (não espera terminar). */
    public function refreshItem(string $itemId): void;

    public function deleteItem(string $itemId): void;

    /** @return list<ProviderAccount> */
    public function accounts(string $itemId): array;

    /**
     * Transações da conta, já paginadas até o fim.
     *
     * @return iterable<ProviderTransaction>
     */
    public function transactions(string $accountId, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable;

    /** @return list<ProviderBill> */
    public function bills(string $accountId): array;
}
