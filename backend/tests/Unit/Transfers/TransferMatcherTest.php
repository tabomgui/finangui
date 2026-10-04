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
        'pending' => false,
        'manualNonTransferCategory' => false,
    ], $overrides));
}

it('liga um par simples e inequívoco, com evidência na descrição', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF ENVIADA']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(1)
        ->and($result['links'][0]->inId)->toBe(2)
        ->and($result['suggestions'])->toBe([]);
});

it('match mútuo único sem nenhuma pista não liga sozinho, mas ainda sugere', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in]);

    expect($result['links'])->toBe([])
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]->outId)->toBe(1)
        ->and($result['suggestions'][0]->inId)->toBe(2);
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

it('match não mútuo: liga o par mútuo (com evidência); o par perdedor da mesma entrada ainda compete por sugestão', function () {
    // S1 e S2 competem pela mesma entrada E; E prefere S2 (pista de descrição),
    // e S2 só tem E como opção — mútuo, liga. S1-E não é mútuo (a preferida
    // de S1 é E, mas a de E é S2) — não liga, mas ainda é um par válido:
    // quem decide se ele deve mesmo virar sugestão (ex.: E já ligou de
    // verdade com S2) é quem chama o matcher, não o matcher em si — ver
    // App\Domain\Transfers\Actions\DetectTransfers, que só exclui
    // sugestões cujo id participou de uma ligação que de fato aconteceu no
    // banco (não apenas "seria ligada" por este resultado puro).
    $s1 = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => '']);
    $s2 = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF PARA CONTA']);
    $e = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$s1, $s2, $e]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(2)
        ->and($result['links'][0]->inId)->toBe(3)
        ->and($result['suggestions'])->toHaveCount(1)
        ->and($result['suggestions'][0]->outId)->toBe(1)
        ->and($result['suggestions'][0]->inId)->toBe(3);
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
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF']);
    $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

    $result = TransferMatcher::match([$out, $in], dismissed: ['1:2' => true]);

    expect($result['links'])->toBe([])->and($result['suggestions'])->toBe([]);
});

it('janela de dias é configurável', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF']);
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

it('bônus de cartão também desempata (entre dois candidatos de mesma pontuação base, o cartão vence)', function () {
    $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'PAGAMENTO']);
    $inComum = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'PAGAMENTO']);
    $inCartao = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'PAGAMENTO', 'creditCard' => true]);

    $result = TransferMatcher::match([$out, $inComum, $inCartao]);

    expect($result['links'])->toHaveCount(1)
        ->and($result['links'][0]->outId)->toBe(1)
        ->and($result['links'][0]->inId)->toBe(3);
});

describe('evidência obrigatória para ligar sozinho (sem evidência, só sugestão)', function () {
    it('"UNITED" não conta como pista de TED, nem "DOCERIA" como DOC', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'UNITED AIRLINES']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'DOCERIA DA ESQUINA']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1); // ainda sugere: pontuação 0,6 ≥ 0,45
    });

    it('conta "Inter" não casa com "INTERNET" na descrição', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'accountName' => 'INTER', 'description' => '']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'CONTA INTERNET']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([]);
    });

    it('nome de conta com menos de 3 letras nunca conta como pista', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'accountName' => 'C6', 'description' => '']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'PAGUEI NO C6 HOJE']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([]);
    });
});

describe('impedimentos de ligação automática (match mútuo único, mas não liga sozinho)', function () {
    it('compra no cartão (saída de cartão) e um PIX recebido de mesmo valor não ligam, mesmo com pista', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'creditCard' => true, 'description' => 'COMPRA LOJA']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'description' => 'PIX RECEBIDO']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1);
    });

    it('entrada em cartão sem pista de pagamento de fatura não liga, mesmo com outra evidência', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF ENVIADA']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'creditCard' => true, 'description' => 'RECEBIDO']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1);
    });

    it('entrada em cartão com ESTORNO não liga, mesmo com PAGAMENTO na descrição', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'PAGAMENTO ENVIADO']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'creditCard' => true, 'description' => 'ESTORNO DE PAGAMENTO']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1);
    });

    it('entrada em cartão com pista de pagamento de fatura, sem ESTORNO, liga normalmente', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'PAGAMENTO ENVIADO']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01', 'creditCard' => true, 'description' => 'PAGAMENTO DE FATURA']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toHaveCount(1);
    });

    it('perna pendente nunca liga sozinha, mesmo com evidência e match mútuo', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01', 'description' => 'TRANSF', 'pending' => true]);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-01']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1);
    });

    it('perna com categoria manual não-transferência nunca liga sozinha ("Estorno" vs assinatura por coincidência)', function () {
        $estorno = transferCandidate([
            'id' => 1, 'accountId' => 1, 'direction' => Direction::In, 'date' => '2026-10-01',
            'description' => 'ESTORNO DE PAGAMENTO', 'manualNonTransferCategory' => true,
        ]);
        $assinatura = transferCandidate([
            'id' => 2, 'accountId' => 2, 'direction' => Direction::Out, 'date' => '2026-10-01',
            'description' => 'PAGAMENTO SPOTIFY', 'manualNonTransferCategory' => true,
        ]);

        $result = TransferMatcher::match([$estorno, $assinatura]);

        expect($result['links'])->toBe([])
            ->and($result['suggestions'])->toHaveCount(1);
    });
});

describe('sugestões: limiar e teto por transação', function () {
    it('pontuação abaixo de 0,45 não vira sugestão', function () {
        // 2 dias de diferença sem nenhuma pista: 0,3 — abaixo do novo limiar.
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-03']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['suggestions'])->toBe([]);
    });

    it('1 dia de diferença, sem pista (0,45), ainda vira sugestão: o limiar é inclusivo', function () {
        $out = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']);
        $in = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::In, 'date' => '2026-10-02']);

        $result = TransferMatcher::match([$out, $in]);

        expect($result['suggestions'])->toHaveCount(1);
    });

    it('no máximo duas sugestões por transação, priorizando a maior pontuação', function () {
        // Quatro saídas competem pela mesma entrada; A e B empatam no topo
        // (0,6) — o empate torna a entrada ambígua para as duas (nenhum
        // par vira ligação de verdade), então as quatro seguem como pares
        // puros disputando as duas vagas de sugestão da entrada. C (0,45)
        // fica de fora pelo teto; D (0,3) já nem entra, abaixo do limiar.
        $in = transferCandidate(['id' => 10, 'accountId' => 10, 'direction' => Direction::In, 'date' => '2026-10-01']);
        $outA = transferCandidate(['id' => 1, 'accountId' => 1, 'direction' => Direction::Out, 'date' => '2026-10-01']); // 0,6
        $outB = transferCandidate(['id' => 2, 'accountId' => 2, 'direction' => Direction::Out, 'date' => '2026-10-01']); // 0,6 (empate com A)
        $outC = transferCandidate(['id' => 3, 'accountId' => 3, 'direction' => Direction::Out, 'date' => '2026-10-02']); // 0,45
        $outD = transferCandidate(['id' => 4, 'accountId' => 4, 'direction' => Direction::Out, 'date' => '2026-10-03']); // 0,3 (abaixo do limiar)

        $result = TransferMatcher::match([$in, $outA, $outB, $outC, $outD]);

        expect($result['links'])->toBe([]);

        $outIds = array_map(fn ($p) => $p->outId, $result['suggestions']);
        expect($outIds)->toBe([1, 2]);
    });
});
