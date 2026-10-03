<?php

use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Support\TransactionMapper;
use App\Domain\Transactions\Enums\Direction;

function providerTransaction(array $overrides = []): ProviderTransaction
{
    return new ProviderTransaction(
        id: $overrides['id'] ?? '00000000-0000-0000-0000-0000000000a1',
        date: $overrides['date'] ?? '2026-03-07',
        amountCents: $overrides['amountCents'] ?? 1500,
        direction: $overrides['direction'] ?? Direction::Out,
        description: $overrides['description'] ?? 'Supermercado',
        pending: $overrides['pending'] ?? false,
        categoryId: $overrides['categoryId'] ?? null,
        installment: $overrides['installment'] ?? null,
        purchaseDate: $overrides['purchaseDate'] ?? null,
        billId: $overrides['billId'] ?? null,
    );
}

it('mapeia os campos básicos e usa o id do provedor como external_id', function () {
    $row = TransactionMapper::toParsedRow(providerTransaction(), false, 3);

    expect($row->line)->toBe(3)
        ->and($row->date)->toBe('2026-03-07')
        ->and($row->amount)->toBe(1500)
        ->and($row->direction)->toBe(Direction::Out)
        ->and($row->description)->toBe('Supermercado')
        ->and($row->externalId)->toBe('00000000-0000-0000-0000-0000000000a1')
        ->and($row->pending)->toBeFalse()
        ->and($row->installment)->toBeNull()
        ->and($row->meta)->toBe([]);
});

it('leva installment só em cartão e na saída', function () {
    $installment = ['number' => 2, 'total' => 10];

    $card = TransactionMapper::toParsedRow(providerTransaction(['installment' => $installment]), true, 1);
    $notCard = TransactionMapper::toParsedRow(providerTransaction(['installment' => $installment]), false, 1);
    $cardIn = TransactionMapper::toParsedRow(providerTransaction(['installment' => $installment, 'direction' => Direction::In]), true, 1);

    expect($card->installment)->toBe($installment)
        ->and($notCard->installment)->toBeNull()
        ->and($cardIn->installment)->toBeNull();
});

it('leva bill_id e provider_category_id para meta quando presentes', function () {
    $row = TransactionMapper::toParsedRow(providerTransaction([
        'billId' => '00000000-0000-0000-0000-0000000000b1',
        'categoryId' => '01010000',
    ]), true, 1);

    expect($row->meta)->toBe([
        'bill_id' => '00000000-0000-0000-0000-0000000000b1',
        'provider_category_id' => '01010000',
    ]);
});

it('pending segue o status da transação do provedor', function () {
    $row = TransactionMapper::toParsedRow(providerTransaction(['pending' => true]), false, 1);

    expect($row->pending)->toBeTrue();
});
