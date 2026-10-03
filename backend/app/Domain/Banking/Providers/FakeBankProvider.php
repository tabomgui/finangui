<?php

namespace App\Domain\Banking\Providers;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use Carbon\CarbonImmutable;
use Generator;
use InvalidArgumentException;
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

    /**
     * Entrega transactionsByAccount[$accountId] em páginas (do tamanho de
     * "pageSize") e, opcionalmente, lança "error" depois de entregar a
     * página "failAfterPage" — simula uma falha no meio da paginação, para
     * testar quem consome transactions() (ex.: retry do job de sync).
     *
     * @var array<string, array{pageSize: int, failAfterPage?: int, error?: Throwable}>
     */
    public array $paginationByAccount = [];

    /** @var array<string, list<ProviderBill>> */
    public array $billsByAccount = [];

    /** @var list<ProviderCategory> */
    public array $categories = [];

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

        // Mesmo formato de erro do provedor de verdade (item inexistente é
        // um 404 na Pluggy), para quem consome não precisar de dois
        // caminhos de erro diferentes entre fake e real.
        return $this->items[$itemId] ?? throw new ProviderRequestFailed(404);
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

    /**
     * @throws InvalidArgumentException
     */
    public function transactions(string $accountId, bool $creditCard, ?CarbonImmutable $dateFrom, ?CarbonImmutable $createdAtFrom): iterable
    {
        if (($dateFrom === null) === ($createdAtFrom === null)) {
            throw new InvalidArgumentException(
                'Informe exatamente um entre $dateFrom (primeiro sync) e $createdAtFrom (syncs seguintes).'
            );
        }

        $this->record('transactions', [
            'accountId' => $accountId,
            'creditCard' => $creditCard,
            'dateFrom' => $dateFrom?->toDateString(),
            'createdAtFrom' => $createdAtFrom?->toDateString(),
        ]);

        return $this->transactionsGenerator($accountId);
    }

    /**
     * @return Generator<int, ProviderTransaction>
     */
    private function transactionsGenerator(string $accountId): Generator
    {
        $all = $this->transactionsByAccount[$accountId] ?? [];
        $pagination = $this->paginationByAccount[$accountId] ?? null;

        if ($pagination === null) {
            yield from $all;

            return;
        }

        $pages = array_chunk($all, max(1, $pagination['pageSize']));
        $failAfterPage = $pagination['failAfterPage'] ?? null;

        foreach ($pages as $index => $page) {
            yield from $page;

            if ($failAfterPage !== null && $index + 1 === $failAfterPage) {
                throw $pagination['error'] ?? new ProviderUnavailable('FakeBankProvider: falha injetada na paginação.');
            }
        }
    }

    public function bills(string $accountId): array
    {
        $this->record('bills', ['accountId' => $accountId]);

        return $this->billsByAccount[$accountId] ?? [];
    }

    public function categories(): array
    {
        $this->record('categories', []);

        return $this->categories;
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
