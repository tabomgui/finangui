<?php

use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function recurrenceDates(array $overrides, string $from, string $to): array
{
    $spec = [
        'frequency' => Frequency::Monthly,
        'interval' => 1,
        'starts_on' => '2026-01-05',
        ...$overrides,
    ];

    return RecurrenceSchedule::dates($spec, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

it('semanal avança de interval semanas em interval semanas', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Weekly, 'interval' => 2, 'starts_on' => '2026-01-05'],
        '2026-01-01',
        '2026-02-28',
    );

    expect($dates)->toBe(['2026-01-05', '2026-01-19', '2026-02-02', '2026-02-16']);
});

it('mensal no dia 31 cai no último dia de fevereiro e abril', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Monthly, 'interval' => 1, 'day_of_month' => 31, 'starts_on' => '2026-01-31'],
        '2026-01-01',
        '2026-04-30',
    );

    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
});

it('mensal com intervalo 3 pula dois meses entre ocorrências', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Monthly, 'interval' => 3, 'day_of_month' => 5, 'starts_on' => '2026-01-05'],
        '2026-01-01',
        '2026-10-31',
    );

    expect($dates)->toBe(['2026-01-05', '2026-04-05', '2026-07-05', '2026-10-05']);
});

it('anual em 29/02 vira 28/02 em ano não bissexto', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Yearly, 'interval' => 1, 'starts_on' => '2024-02-29'],
        '2024-01-01',
        '2027-12-31',
    );

    expect($dates)->toBe(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28']);
});

it('nunca gera antes de starts_on mesmo pedindo um intervalo anterior', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Monthly, 'interval' => 1, 'day_of_month' => 5, 'starts_on' => '2026-03-05'],
        '2026-01-01',
        '2026-05-31',
    );

    expect($dates)->toBe(['2026-03-05', '2026-04-05', '2026-05-05']);
});

it('respeita ends_on como limite final', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Monthly, 'interval' => 1, 'day_of_month' => 5, 'starts_on' => '2026-01-05', 'ends_on' => '2026-03-10'],
        '2026-01-01',
        '2026-06-30',
    );

    expect($dates)->toBe(['2026-01-05', '2026-02-05', '2026-03-05']);
});

it('intervalo sem nenhuma ocorrência devolve lista vazia', function () {
    $dates = recurrenceDates(
        ['frequency' => Frequency::Monthly, 'interval' => 1, 'day_of_month' => 5, 'starts_on' => '2026-06-05'],
        '2026-01-01',
        '2026-05-31',
    );

    expect($dates)->toBe([]);
});
