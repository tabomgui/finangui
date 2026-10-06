<?php

namespace App\Domain\Banking\Contracts;

use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Isola o provedor de open finance (Pluggy em produção, fake em teste) do
 * resto do domínio Banking. Uma instância fala sempre pelas credenciais de
 * um único usuário — montada por {@see BankProviderFactory::for()}, nunca
 * resolvida direto do container.
 */
interface BankProvider
{
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
     * Exatamente um entre $dateFrom (primeiro sync) e $createdAtFrom (syncs
     * seguintes) deve ser informado; o outro, null.
     *
     * @return iterable<ProviderTransaction>
     *
     * @throws InvalidArgumentException quando os dois (ou nenhum) são informados
     */
    public function transactions(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable;

    /** @return list<ProviderBill> */
    public function bills(string $accountId): array;

    /**
     * Todas as categorias do provedor (cacheadas): base para a
     * categorização por nome (ver App\Domain\Banking\Support\ProviderCategoryMatcher).
     * Lista vazia quando a busca falhar — categorização por nome fica
     * desligada até o cache vencer, mas o resto do sync segue.
     *
     * @return list<ProviderCategory>
     */
    public function categories(): array;
}
