<?php

use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderRequestFailed;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Transactions\Enums\Direction;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->fake = new FakeBankProvider;
});

it('item não configurado lança ProviderRequestFailed(404), igual ao provedor de verdade', function () {
    try {
        $this->fake->item('unknown-item');
        $this->fail('deveria ter lançado ProviderRequestFailed');
    } catch (ProviderRequestFailed $e) {
        expect($e->status)->toBe(404);
    }
});

it('transactions() exige exatamente um entre dateFrom e createdAtFrom', function () {
    expect(fn () => $this->fake->transactions('acc-1', false, null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->fake->transactions('acc-1', false, CarbonImmutable::now(), CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
});

it('sem paginação configurada, entrega todas as transações de uma vez', function () {
    $tx = new ProviderTransaction('tx-1', '2026-03-07', 1000, Direction::Out, 'x', false, null, null, null, null);
    $this->fake->transactionsByAccount['acc-1'] = [$tx];

    $result = iterator_to_array($this->fake->transactions('acc-1', false, CarbonImmutable::now(), null));

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($tx);
});

it('pagina em pedaços e injeta falha depois da página configurada', function () {
    $pages = [
        new ProviderTransaction('tx-1', '2026-03-01', 100, Direction::Out, 'a', false, null, null, null, null),
        new ProviderTransaction('tx-2', '2026-03-02', 200, Direction::Out, 'b', false, null, null, null, null),
        new ProviderTransaction('tx-3', '2026-03-03', 300, Direction::Out, 'c', false, null, null, null, null),
    ];

    $this->fake->transactionsByAccount['acc-1'] = $pages;
    $this->fake->paginationByAccount['acc-1'] = ['pageSize' => 1, 'failAfterPage' => 2];

    $generator = $this->fake->transactions('acc-1', false, CarbonImmutable::now(), null);

    $seen = [];

    try {
        foreach ($generator as $transaction) {
            $seen[] = $transaction->id;
        }
        $this->fail('deveria ter lançado ProviderUnavailable depois da página 2');
    } catch (ProviderUnavailable) {
        // esperado
    }

    expect($seen)->toBe(['tx-1', 'tx-2']);
});

it('categories() devolve o que o teste configurou e registra a chamada', function () {
    $category = new ProviderCategory('0101', 'Salário', '0100');
    $this->fake->categories = [$category];

    expect($this->fake->categories())->toBe([$category]);
    expect($this->fake->calls)->toContain(['method' => 'categories', 'args' => []]);
});

it('failNext lança o erro configurado na próxima chamada a qualquer método', function () {
    $this->fake->failNext(new ProviderUnavailable('fora do ar'));

    expect(fn () => $this->fake->accounts('item-1'))->toThrow(ProviderUnavailable::class);

    // Só a próxima chamada falha; esta já funciona normalmente.
    expect($this->fake->accounts('item-1'))->toBe([]);
});
