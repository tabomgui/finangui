<?php

use App\Domain\Cards\Support\InvoiceCycle;
use Carbon\CarbonImmutable;

function cycle(string $date, int $closing, int $due): array
{
    $dates = InvoiceCycle::forPurchase(CarbonImmutable::parse($date), $closing, $due);

    return [$dates->closingDate->toDateString(), $dates->dueDate->toDateString()];
}

it('compra antes do fechamento entra na fatura do mês', function () {
    expect(cycle('2026-03-05', 10, 20))->toBe(['2026-03-10', '2026-03-20']);
});

it('compra no dia do fechamento vai para a próxima fatura', function () {
    expect(cycle('2026-03-10', 10, 20))->toBe(['2026-04-10', '2026-04-20']);
});

it('compra depois do fechamento vai para a próxima fatura', function () {
    expect(cycle('2026-03-25', 10, 20))->toBe(['2026-04-10', '2026-04-20']);
});

it('vencimento menor que o fechamento cai no mês seguinte', function () {
    expect(cycle('2026-03-01', 25, 5))->toBe(['2026-03-25', '2026-04-05']);
});

it('vencimento no mesmo dia do fechamento cai no mês seguinte', function () {
    expect(cycle('2026-03-01', 10, 10))->toBe(['2026-03-10', '2026-04-10']);
});

it('fechamento 31 em fevereiro é limitado ao último dia', function () {
    expect(cycle('2026-02-15', 31, 10))->toBe(['2026-02-28', '2026-03-10']);
    expect(cycle('2028-02-15', 31, 10))->toBe(['2028-02-29', '2028-03-10']);
});

it('compra no último dia de fevereiro com fechamento 31 vai para março', function () {
    expect(cycle('2026-02-28', 31, 10))->toBe(['2026-03-31', '2026-04-10']);
});

it('vencimento 31 é limitado ao último dia do mês do vencimento', function () {
    expect(cycle('2026-01-20', 25, 31))->toBe(['2026-01-25', '2026-01-31']);
    expect(cycle('2026-02-10', 25, 31))->toBe(['2026-02-25', '2026-02-28']);
});

it('vira o ano', function () {
    expect(cycle('2026-12-20', 15, 25))->toBe(['2027-01-15', '2027-01-25']);
    expect(cycle('2026-12-10', 28, 5))->toBe(['2026-12-28', '2027-01-05']);
});

it('próxima e anterior seguem o dia nominal, sem acumular o limite de fim de mês', function () {
    $jan = InvoiceCycle::forPurchase(CarbonImmutable::parse('2026-01-10'), 31, 10);
    $feb = InvoiceCycle::next($jan, 31, 10);
    $mar = InvoiceCycle::next($feb, 31, 10);

    expect($jan->closingDate->toDateString())->toBe('2026-01-31')
        ->and($feb->closingDate->toDateString())->toBe('2026-02-28')
        ->and($feb->dueDate->toDateString())->toBe('2026-03-10')
        ->and($mar->closingDate->toDateString())->toBe('2026-03-31')
        ->and(InvoiceCycle::previous($mar, 31, 10)->closingDate->toDateString())->toBe('2026-02-28');
});

it('evita colisão de vencimento quando o dia clampado cai no próprio fechamento', function () {
    expect(cycle('2026-02-27', 28, 30))->toBe(['2026-02-28', '2026-03-01']);
    expect(cycle('2026-03-05', 28, 30))->toBe(['2026-03-28', '2026-03-30']);
    expect(cycle('2026-04-29', 30, 31))->toBe(['2026-04-30', '2026-05-01']);
});

it('ignora a hora da data da compra', function () {
    $dates = InvoiceCycle::forPurchase(CarbonImmutable::parse('2026-03-10 23:59:00'), 10, 20);

    expect($dates->closingDate->toDateString())->toBe('2026-04-10');
});

it('forClosingMonth usa o dia de fechamento informado, clampado no mês dado', function () {
    $dates = InvoiceCycle::forClosingMonth(CarbonImmutable::parse('2026-03-15'), 20, 28);

    expect($dates->closingDate->toDateString())->toBe('2026-03-20')
        ->and($dates->dueDate->toDateString())->toBe('2026-03-28');
});

it('forClosingMonth clampa o fechamento no fim de um mês curto', function () {
    $dates = InvoiceCycle::forClosingMonth(CarbonImmutable::parse('2026-02-10'), 31, 10);

    expect($dates->closingDate->toDateString())->toBe('2026-02-28')
        ->and($dates->dueDate->toDateString())->toBe('2026-03-10');
});

it('closingForDueDate acha o fechamento do mesmo mês do vencimento (vencimento depois do fechamento)', function () {
    $closing = InvoiceCycle::closingForDueDate(CarbonImmutable::parse('2026-03-20'), 10, 20);

    expect($closing->toDateString())->toBe('2026-03-10');
});

it('closingForDueDate acha o fechamento do mês anterior quando o vencimento cai no mês seguinte', function () {
    $closing = InvoiceCycle::closingForDueDate(CarbonImmutable::parse('2026-04-05'), 25, 5);

    expect($closing->toDateString())->toBe('2026-03-25');
});

it('closingForDueDate reproduz o fechamento mesmo clampado no fim de um mês curto', function () {
    $closing = InvoiceCycle::closingForDueDate(CarbonImmutable::parse('2026-03-10'), 31, 10);

    expect($closing->toDateString())->toBe('2026-02-28');
});

it('closingForDueDate sem ciclo exato (dias do cartão mudaram) usa o fechamento nominal mais recente antes do vencimento', function () {
    // due=25 só existiria com dueDay=25 num mês em que closingDay=10; se os
    // dias do cartão mudaram para closingDay=15/dueDay=28 depois que o banco
    // informou esta fatura, nenhum ciclo reproduz exatamente 2026-03-25 —
    // cai no fechamento nominal (dia 15) mais recente antes do vencimento,
    // que neste caso é o do próprio mês.
    $closing = InvoiceCycle::closingForDueDate(CarbonImmutable::parse('2026-03-25'), 15, 28);

    expect($closing->toDateString())->toBe('2026-03-15');
});

it('closingForDueDate: vencimento deslocado por alguns dias (fechamento 25, vencimento nominal 5, banco informa 07)', function () {
    // Nominal seria fechamento=2026-06-25 → vencimento=2026-07-05 (dueDay <
    // closingDay cruza o mês); o banco informou 2026-07-07 (atraso de 2
    // dias) — nenhum ciclo exato bate, mas o fechamento nominal mais recente
    // antes de 07/07 ainda é 25/06, dentro dos 40 dias.
    $closing = InvoiceCycle::closingForDueDate(CarbonImmutable::parse('2026-07-07'), 25, 5);

    expect($closing->toDateString())->toBe('2026-06-25');
});
