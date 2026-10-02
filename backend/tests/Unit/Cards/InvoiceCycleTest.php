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
