<?php

use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Support\CardPaymentCandidate;
use App\Domain\Banking\Support\CardPaymentMatcher;

function paymentCandidate(
    int $id,
    int $amountCents,
    string $date,
    string $description = 'x',
    bool $isTransferLeg = false,
    bool $isProviderSourced = true,
    bool $isPending = false,
): CardPaymentCandidate {
    return new CardPaymentCandidate($id, $amountCents, $date, $description, $isTransferLeg, $isProviderSourced, $isPending);
}

function billWithPayment(string $billId, string $closingDate, string $paymentId, int $amountCents, string $paymentDate): ProviderBill
{
    return new ProviderBill(
        id: $billId,
        dueDate: '2026-11-10',
        closingDate: $closingDate,
        totalCents: 100000,
        payments: [new ProviderBillPayment($paymentId, $paymentDate, $amountCents)],
    );
}

function paymentDecisionFor(array $decisions, int $id): ?object
{
    foreach ($decisions as $decision) {
        if ($decision->transactionId === $id) {
            return $decision;
        }
    }

    return null;
}

it('reconhece perna de transferência como pagamento, sem fatura nem padrão de descrição', function () {
    $candidates = [paymentCandidate(1, 58000, '2026-06-14', 'qualquer coisa', isTransferLeg: true)];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1))->not->toBeNull()
        ->and(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse();
});

it('não reconhece uma entrada comum (sem transferência, fatura ou padrão)', function () {
    $candidates = [paymentCandidate(1, 3000, '2026-06-14', 'Reembolso de amigo')];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1))->toBeNull();
});

it('casa com um pagamento informado por uma fatura do banco, por valor e data a ±3 dias', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 58000, '2026-06-14')];
    $candidates = [paymentCandidate(1, 58000, '2026-06-16', 'descricao qualquer')];

    $decisions = CardPaymentMatcher::match($candidates, $bills);
    $decision = paymentDecisionFor($decisions, 1);

    expect($decision)->not->toBeNull()
        ->and($decision->isDuplicate)->toBeFalse()
        ->and($decision->billExternalId)->toBe('fatura-junho');
});

it('fora da janela de 3 dias não casa com o pagamento da fatura', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 58000, '2026-06-14')];
    $candidates = [paymentCandidate(1, 58000, '2026-06-20', 'descricao qualquer')];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 1))->toBeNull();
});

it('reconhece pelo padrão de descrição quando não há pagamento de fatura do banco disponível', function () {
    $candidates = [paymentCandidate(1, 10000, '2026-06-14', 'PAGAMENTO RECEBIDO')];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse();
});

it('padrão de descrição ignora acento e caixa', function () {
    $candidates = [paymentCandidate(1, 10000, '2026-06-14', 'Pagaménto Recebído')];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1))->not->toBeNull();
});

it('crédito vindo do banco duplicado para o mesmo pagamento da fatura é deduplicado', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 58000, '2026-06-14')];

    $candidates = [
        paymentCandidate(1, 58000, '2026-06-14', 'Pagamento recebido'),
        paymentCandidate(2, 58000, '2026-06-14', 'Pagto debito automatico'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    $first = paymentDecisionFor($decisions, 1);
    $second = paymentDecisionFor($decisions, 2);

    expect($first->isDuplicate)->not->toBe($second->isDuplicate);

    $chosen = $first->isDuplicate ? $second : $first;
    expect($chosen->billExternalId)->toBe('fatura-junho');
});

it('dedup prefere a perna de transferência entre os duplicados, mesmo quando ela não é a mais próxima da data do banco', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 5000, '2026-06-14')];

    $candidates = [
        // Mais perto da data do banco, mas não é transferência.
        paymentCandidate(1, 5000, '2026-06-14', 'Pagamento recebido', isTransferLeg: false),
        // Um pouco mais longe, mas é a transferência confirmada pelo usuário.
        paymentCandidate(2, 5000, '2026-06-15', 'Pagamento de fatura', isTransferLeg: true),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->billExternalId)->toBe('fatura-junho')
        ->and(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeTrue();
});

it('sem transferência nenhuma no grupo, dedup prefere o lançado (posted) ao pendente', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 5000, '2026-06-14')];

    $candidates = [
        paymentCandidate(1, 5000, '2026-06-14', 'Pagamento recebido', isPending: true),
        paymentCandidate(2, 5000, '2026-06-14', 'Pagamento recebido', isPending: false),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeTrue();
});

it('nunca marca uma perna de transferência como duplicata, mesmo com outra perna de transferência igual por perto (dois pagamentos manuais do mesmo valor)', function () {
    $candidates = [
        paymentCandidate(1, 12000, '2026-06-10', 'Pagamento de fatura', isTransferLeg: true),
        paymentCandidate(2, 12000, '2026-06-11', 'Pagamento de fatura', isTransferLeg: true),
    ];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse();
});

it('nunca marca um lançamento manual como duplicata, mesmo perto de outro crédito do banco de mesmo valor', function () {
    $candidates = [
        paymentCandidate(1, 9000, '2026-06-10', 'Pagamento recebido', isProviderSourced: false),
        paymentCandidate(2, 9000, '2026-06-11', 'Pagamento recebido', isProviderSourced: true),
    ];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeTrue();
});

it('um reembolso do mesmo valor perto de um pagamento nunca é tratado como pagamento nem como duplicata', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 20000, '2026-06-14')];

    $candidates = [
        // O pagamento de verdade, bem perto da data informada pelo banco.
        paymentCandidate(1, 20000, '2026-06-14', 'Pagamento recebido', isTransferLeg: true),
        // Reembolso de uma compra, mesmo valor, descrição que não bate com nenhum padrão.
        paymentCandidate(2, 20000, '2026-06-16', 'Reembolso Loja Exemplo'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2))->toBeNull();
});

it('dois pagamentos legítimos de mesmo valor em faturas diferentes nunca são confundidos', function () {
    $bills = [
        billWithPayment('fatura-junho', '2026-06-05', 'pag-jun', 50000, '2026-06-14'),
        billWithPayment('fatura-julho', '2026-07-05', 'pag-jul', 50000, '2026-07-14'),
    ];

    $candidates = [
        paymentCandidate(1, 50000, '2026-06-14', 'Pagamento recebido'),
        paymentCandidate(2, 50000, '2026-07-14', 'Pagamento recebido'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 1)->billExternalId)->toBe('fatura-junho')
        ->and(paymentDecisionFor($decisions, 2)->billExternalId)->toBe('fatura-julho');
});

it('duas parcelas iguais pagas em meses diferentes, sem fatura do banco disponível, continuam distintas pela distância de data', function () {
    $candidates = [
        paymentCandidate(1, 50000, '2026-06-14', 'Pagamento recebido'),
        paymentCandidate(2, 50000, '2026-07-14', 'Pagamento recebido'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse();
});

it('pagamento sem par na conta continua reconhecido (o casamento não depende de uma transferência ligada)', function () {
    $candidates = [paymentCandidate(1, 5000, '2026-06-14', 'Pagamento de fatura')];

    $decisions = CardPaymentMatcher::match($candidates, []);

    expect(paymentDecisionFor($decisions, 1))->not->toBeNull()
        ->and(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse();
});

it('pagamentos nos dias 1, 4, 7 e 10 do mesmo valor: compara contra a âncora fixa, não numa janela encadeada', function () {
    $candidates = [
        paymentCandidate(1, 8000, '2026-06-01', 'Pagamento recebido'),
        paymentCandidate(2, 8000, '2026-06-04', 'Pagamento recebido'),
        paymentCandidate(3, 8000, '2026-06-07', 'Pagamento recebido'),
        paymentCandidate(4, 8000, '2026-06-10', 'Pagamento recebido'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, []);

    // Dia 1 é a âncora do primeiro grupo; dia 4 está a 3 dias dele (dentro da
    // janela) e é duplicata. Dia 7 está a 6 dias do dia 1 (fora da janela) —
    // vira uma nova âncora. Dia 10 está a 3 dias do dia 7 — duplicata dele.
    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeTrue()
        ->and(paymentDecisionFor($decisions, 3)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 4)->isDuplicate)->toBeTrue();
});

it('o mesmo pagamento listado em duas faturas prefere a de fechamento mais recente até a data do pagamento', function () {
    $bills = [
        billWithPayment('fatura-antiga', '2026-05-05', 'pag-1', 30000, '2026-06-14'),
        billWithPayment('fatura-nova', '2026-06-05', 'pag-1', 30000, '2026-06-14'),
    ];

    $candidates = [paymentCandidate(1, 30000, '2026-06-14', 'Pagamento recebido')];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 1)->billExternalId)->toBe('fatura-nova');
});

it('dois pagamentos de ids distintos no mesmo valor e dia, dentro da mesma fatura, ficam em vagas separadas', function () {
    $bill = new ProviderBill(
        id: 'fatura-junho', dueDate: '2026-06-20', closingDate: '2026-06-05', totalCents: 200000,
        payments: [
            new ProviderBillPayment('pag-a', '2026-06-14', 25000),
            new ProviderBillPayment('pag-b', '2026-06-14', 25000),
        ],
    );

    $candidates = [
        paymentCandidate(1, 25000, '2026-06-14', 'Pagamento recebido'),
        paymentCandidate(2, 25000, '2026-06-14', 'Pagamento recebido'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, [$bill]);

    expect(paymentDecisionFor($decisions, 1)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse();
});

it('candidato com descrição de pagamento reivindica a vaga antes de um reembolso mais próximo da data do banco', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 20000, '2026-06-14')];

    $candidates = [
        // Mais perto da data do banco, mas é um reembolso — nunca a vaga.
        paymentCandidate(1, 20000, '2026-06-14', 'Reembolso Loja Exemplo'),
        // Mais longe, mas a descrição bate com o padrão de pagamento.
        paymentCandidate(2, 20000, '2026-06-16', 'Pagamento recebido'),
    ];

    $decisions = CardPaymentMatcher::match($candidates, $bills);

    expect(paymentDecisionFor($decisions, 2)->billExternalId)->toBe('fatura-junho')
        ->and(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 1))->toBeNull();
});

it('uma transação travada (isLocked) reivindica a vaga e nunca é duplicata, mesmo sem ser perna de transferência', function () {
    $bills = [billWithPayment('fatura-junho', '2026-06-05', 'pag-1', 18000, '2026-06-14')];

    $candidates = [
        paymentCandidate(1, 18000, '2026-06-14', 'Credito qualquer', isProviderSourced: true),
    ];
    $locked = new CardPaymentCandidate(2, 18000, '2026-06-15', 'Pagamento recebido', false, true, false, true);

    $decisions = CardPaymentMatcher::match([...$candidates, $locked], $bills);

    expect(paymentDecisionFor($decisions, 2)->isDuplicate)->toBeFalse()
        ->and(paymentDecisionFor($decisions, 2)->billExternalId)->toBe('fatura-junho')
        ->and(paymentDecisionFor($decisions, 1))->toBeNull();
});
