<?php

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transfers\Data\TransferCandidate;
use App\Domain\Transfers\Support\TransferMatcher;

function transferCandidate(array $overrides = []): TransferCandidate
{
    return new TransferCandidate(...array_merge([
        'id' => 1,
        'accountId' => 1,
        'accountName' => '',
        'creditCard' => false,
        'currency' => 'BRL',
        'direction' => Direction::Out,
        'amount' => 50000,
        'date' => '2026-10-01',
        'description' => '',
    ], $overrides));
}

it('liga um par simples e inequívoco', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(1)
        ->and($result['links'][0]->inId)->toBe(2)
        ->and($result['suggestions'])->toBe([]);
});

it('duas entradas com a mesma pontuação: nenhuma ligação, duas sugestões', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in1 = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);
    $in2 = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in1, $in2]);

    expect($result['links'])->toBe([])
        ->and($result['suggestions'])->toHaveCount(2);

    $pairs = array_map(fn ($p) => [$p->outId, $p->inId], $result['suggestions']);
    expect($pairs)->toBe([[1, 2], [1, 3]]);
});

it('match não mútuo: liga o par mútuo e descarta a sugestão da transação já ligada', function () {
    // S1 e S2 competem pela mesma entrada E; E prefere S2 (pista de descrição),
    // e S2 só tem E como opção — mútuo. S1-E não é mútuo e some das sugestões
    // porque E já está ligada a S2.
    $s1 = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => '']);
    $s2 = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF PARA CONTA']);
    $e = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$s1, $s2, $e]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(2)
        ->and($result['links'][0]->inId)->toBe(3)
        ->and($result['suggestions'])->toBe([]);
});

it('mesma conta não forma par', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out]);
    $in = transferCandidate(['id' => 2, 'accountId' => 1, 'direction' => Direction::In]);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('moedas diferentes não formam par', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'currency' => 'BRL']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'currency' => 'USD']);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('mais de 2 dias de diferença não forma par', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-04']);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('valores diferentes não formam par', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'amount' => 1000]);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'amount' => 1001]);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('par descartado não vira ligação nem sugestão', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in], dismissed: ['1:2' => true]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('janela de dias é configurável', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-06']);

    expect(TransferMatcher::match([$out, $in])['links'])->toBe([])
        ->and(TransferMatcher::match([$out, $in], maxDays: 7)['links'])->toHaveCount(1);
});

it('pontuação por data: 0, 1 e 2 dias', function () {
    $out = transferCandidate(['date' => '2026-10-01']);

    expect(TransferMatcher::score($out, transferCandidate(['date' => '2026-10-01', 'direction' => Direction::In])))->toBe(0.6)
        ->and(TransferMatcher::score($out, transferCandidate(['date' => '2026-10-02', 'direction' => Direction::In])))->toBe(0.45)
        ->and(TransferMatcher::score($out, transferCandidate(['date' => '2026-10-03', 'direction' => Direction::In])))->toBe(0.3)
        ->and(TransferMatcher::score($out, transferCandidate(['date' => '2026-10-10', 'direction' => Direction::In])))->toBe(0.0);
});

it('pista de descrição soma 0,2 e o nome da outra conta conta como pista', function () {
    $out = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::Out, 'description' => 'SEM PISTA']);
    $in = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::In, 'description' => 'SEM PISTA']);

    expect(TransferMatcher::score($out, $in))->toBe(0.6);

    $comPix = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::In, 'description' => 'PIX ENVIADO']);
    expect(TransferMatcher::score($out, $comPix))->toBe(0.8);

    $outComNomeDaConta = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::Out, 'description' => 'PARA NUBANK']);
    $inNomeadaNubank = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::In, 'accountName' => 'NUBANK', 'description' => 'SEM PISTA']);
    expect(TransferMatcher::score($outComNomeDaConta, $inNomeadaNubank))->toBe(0.8);
});

it('entrada em cartão de crédito soma 0,2, com máximo 1', function () {
    $out = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::Out, 'description' => 'TRANSF']);
    $inCartao = transferCandidate(['date' => '2026-10-01', 'direction' => Direction::In, 'creditCard' => true, 'description' => 'TRANSF']);

    expect(TransferMatcher::score($out, $inCartao))->toBe(1.0);
});

it('pista de descrição desempata uma entrada entre duas candidatas', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => '']);
    $inSemPista = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => '']);
    $inComPista = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'TRANSF RECEBIDA']);

    $result = TransferMatcher::match([$out, $inSemPista, $inComPista]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(1)
        ->and($result['links'][0]->inId)->toBe(3);
});
