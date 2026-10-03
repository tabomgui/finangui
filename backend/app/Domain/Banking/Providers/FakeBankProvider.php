<?php

namespace App\Domain\Banking\Providers;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Provedor em memória para testes de domínio/HTTP que não devem falar com a
 * Pluggy de verdade. Configurado pelo próprio teste (`$fake->items[...]`
 * etc.); `$fake->calls` registra toda chamada recebida, na ordem.
 */
final class FakeBankProvider implements BankProvider
{
    /** @var array<string, ProviderItem> */
    public array $items = [];

    /** @var array<string, list<ProviderAccount>> */
    public array $accountsByItem = [];

    /** @var array<string, list<ProviderTransaction>> */
    public array $transactionsByAccount = [];

    /** @var array<string, list<ProviderBill>> */
    public array $billsByAccount = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    private bool $enabled = true;

    private ?Throwable $nextFailure = null;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /** A próxima chamada a qualquer método lança $error em vez de responder. */
    public function failNext(Throwable $error): void
    {
        $this->nextFailure = $error;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function connectToken(string $clientUserId, ?string $itemId = null): string
    {
        $this->record('connectToken', ['clientUserId' => $clientUserId, 'itemId' => $itemId]);

        return 'fake-connect-token';
    }

    public function item(string $itemId): ProviderItem
    {
        $this->record('item', ['itemId' => $itemId]);

        return $this->items[$itemId]
            ?? throw new RuntimeException("FakeBankProvider: item [{$itemId}] não configurado em \$fake->items.");
    }

    public function refreshItem(string $itemId): void
    {
        $this->record('refreshItem', ['itemId' => $itemId]);
    }

    public function deleteItem(string $itemId): void
    {
        $this->record('deleteItem', ['itemId' => $itemId]);
    }

    public function accounts(string $itemId): array
    {
        $this->record('accounts', ['itemId' => $itemId]);

        return $this->accountsByItem[$itemId] ?? [];
    }

    public function transactions(string $accountId, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
    {
        $this->record('transactions', [
            'accountId' => $accountId,
            'dateFrom' => $dateFrom?->toDateString(),
            'createdAtFrom' => $createdAtFrom?->toDateString(),
        ]);

        return $this->transactionsByAccount[$accountId] ?? [];
    }

    public function bills(string $accountId): array
    {
        $this->record('bills', ['accountId' => $accountId]);

        return $this->billsByAccount[$accountId] ?? [];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];

        if ($this->nextFailure !== null) {
            $failure = $this->nextFailure;
            $this->nextFailure = null;

            throw $failure;
        }
    }
}
