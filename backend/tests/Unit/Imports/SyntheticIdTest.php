<?php

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Support\SyntheticId;
use App\Domain\Transactions\Enums\Direction;

it('gera o mesmo id para a mesma entrada', function () {
    $a = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'Padaria', 0);
    $b = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'Padaria', 0);

    expect($a)->toBe($b)
        ->and($a)->toStartWith('h:');
});

it('gera ids diferentes para ocorrências diferentes', function () {
    $a = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'Padaria', 0);
    $b = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'Padaria', 1);

    expect($a)->not->toBe($b);
});

it('gera o mesmo id para descrição com acento/caixa diferente', function () {
    $a = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'PADARIA SÃO JOÃO', 0);
    $b = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'padaria sao joao', 0);

    expect($a)->toBe($b);
});

it('gera ids diferentes para formatos, datas, valores ou direções diferentes', function () {
    $base = SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::Out, 'Padaria', 0);

    expect(SyntheticId::for(ImportFormat::Nubank, '2026-01-05', 1000, Direction::Out, 'Padaria', 0))->not->toBe($base)
        ->and(SyntheticId::for(ImportFormat::Inter, '2026-01-06', 1000, Direction::Out, 'Padaria', 0))->not->toBe($base)
        ->and(SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1001, Direction::Out, 'Padaria', 0))->not->toBe($base)
        ->and(SyntheticId::for(ImportFormat::Inter, '2026-01-05', 1000, Direction::In, 'Padaria', 0))->not->toBe($base);
});
