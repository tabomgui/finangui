<?php

use App\Domain\Banking\Actions\ReconcileCardPayments;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use Carbon\CarbonImmutable;

it('sem faturas, devolve null', function () {
    expect(ReconcileCardPayments::earliestBillClosing([]))->toBeNull();
});

it('usa o fechamento informado quando presente, sem payments[]', function () {
    $bills = [
        new ProviderBill(id: 'a', dueDate: '2026-04-20', closingDate: '2026-04-10', totalCents: 1000),
        new ProviderBill(id: 'b', dueDate: '2026-05-20', closingDate: '2026-05-10', totalCents: 1000),
    ];

    expect(ReconcileCardPayments::earliestBillClosing($bills))->toEqual(CarbonImmutable::parse('2026-04-10'));
});

it('fatura sem closingDate nunca é ignorada: cai para o vencimento menos 40 dias, mesmo quando é a mais antiga do lote', function () {
    $bills = [
        // Fatura antiga, de antes do cartão ter os dias atuais: o banco não informa o fechamento.
        new ProviderBill(id: 'antiga', dueDate: '2026-02-10', closingDate: null, totalCents: 1000),
        // Fatura recente, já com fechamento informado.
        new ProviderBill(id: 'recente', dueDate: '2026-09-10', closingDate: '2026-09-01', totalCents: 1000),
    ];

    // Sem a fatura antiga entrar na conta (pulada por falta de closingDate),
    // o resultado seria 2026-09-01 — tarde demais para cobrir pagamentos de
    // fevereiro. Com ela entrando pelo vencimento (2026-02-10) menos os 40
    // dias máximos entre fechamento e vencimento, o resultado cobre bem o
    // ciclo inteiro da fatura antiga, mesmo sem saber o fechamento real.
    expect(ReconcileCardPayments::earliestBillClosing($bills))->toEqual(CarbonImmutable::parse('2026-01-01'));
});

it('uma data de payments[] mais antiga que o próprio piso de fechamento/vencimento também baixa a referência (menos a janela de 3 dias)', function () {
    $bills = [
        new ProviderBill(
            id: 'a', dueDate: '2026-04-20', closingDate: '2026-04-10', totalCents: 1000,
            // Pagamento informado bem antes do fechamento — dado estranho do
            // banco, mas a janela calculada aqui nunca pode deixá-lo de fora.
            payments: [new ProviderBillPayment('pag-1', '2026-03-01', 1000)],
        ),
    ];

    expect(ReconcileCardPayments::earliestBillClosing($bills))->toEqual(CarbonImmutable::parse('2026-02-26'));
});

it('payments[] dentro do piso normal não muda a referência', function () {
    $bills = [
        new ProviderBill(
            id: 'a', dueDate: '2026-04-20', closingDate: '2026-04-10', totalCents: 1000,
            payments: [new ProviderBillPayment('pag-1', '2026-04-14', 1000)],
        ),
    ];

    expect(ReconcileCardPayments::earliestBillClosing($bills))->toEqual(CarbonImmutable::parse('2026-04-10'));
});
