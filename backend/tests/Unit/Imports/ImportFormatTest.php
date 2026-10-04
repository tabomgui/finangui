<?php

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Support\FormatDetector;
use App\Domain\Transactions\Enums\TransactionSource;

it('pluggy usa TransactionSource::Pluggy e um rótulo próprio', function () {
    expect(ImportFormat::Pluggy->source())->toBe(TransactionSource::Pluggy)
        ->and(ImportFormat::Pluggy->label())->toBe('Sincronização bancária');
});

it('ofx usa TransactionSource::Ofx; os demais formatos de arquivo usam Csv', function () {
    expect(ImportFormat::Ofx->source())->toBe(TransactionSource::Ofx)
        ->and(ImportFormat::Inter->source())->toBe(TransactionSource::Csv)
        ->and(ImportFormat::Nubank->source())->toBe(TransactionSource::Csv)
        ->and(ImportFormat::NubankCard->source())->toBe(TransactionSource::Csv)
        ->and(ImportFormat::C6->source())->toBe(TransactionSource::Csv);
});

it('pluggy não tem parser de arquivo', function () {
    FormatDetector::parser(ImportFormat::Pluggy);
})->throws(LogicException::class);
