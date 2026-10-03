<?php

use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Transactions\Enums\Direction;

it('toArray/fromArray fazem o ciclo completo com installment', function () {
    $row = new ParsedRow(
        line: 7,
        date: '2026-03-05',
        amount: 1590,
        direction: Direction::Out,
        description: 'Loja Exemplo',
        externalId: 'h:abc123',
        installment: ['number' => 2, 'total' => 10],
        pending: true,
    );

    $restored = ParsedRow::fromArray($row->toArray());

    expect($restored->line)->toBe($row->line)
        ->and($restored->date)->toBe($row->date)
        ->and($restored->amount)->toBe($row->amount)
        ->and($restored->direction)->toBe($row->direction)
        ->and($restored->description)->toBe($row->description)
        ->and($restored->externalId)->toBe($row->externalId)
        ->and($restored->installment)->toBe($row->installment)
        ->and($restored->pending)->toBe($row->pending);
});

it('toArray/fromArray fazem o ciclo completo sem installment e com os defaults', function () {
    $row = new ParsedRow(
        line: 1,
        date: '2026-01-01',
        amount: 100,
        direction: Direction::In,
        description: 'Pix recebido',
        externalId: 'FITID-1',
    );

    $restored = ParsedRow::fromArray($row->toArray());

    expect($restored->installment)->toBeNull()
        ->and($restored->pending)->toBeFalse()
        ->and($restored->toArray())->toBe($row->toArray());
});
