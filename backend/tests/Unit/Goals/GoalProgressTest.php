<?php

use App\Domain\Goals\Support\GoalProgress;
use Carbon\CarbonImmutable;

it('calcula restante, percentual e ritmo mensal a partir de uma data futura', function () {
    $result = GoalProgress::calculate(40000, 100000, CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-01-15'));

    expect($result['progress'])->toBe(40000)
        ->and($result['remaining'])->toBe(60000)
        ->and($result['percent'])->toBe(40)
        // jan -> abr = 3 meses
        ->and($result['monthly_needed'])->toBe(20000);
});

it('usa no mínimo 1 mês quando o alvo é no mês corrente', function () {
    $result = GoalProgress::calculate(0, 90000, CarbonImmutable::parse('2026-01-20'), CarbonImmutable::parse('2026-01-05'));

    expect($result['monthly_needed'])->toBe(90000);
});

it('usa no mínimo 1 mês quando o alvo já passou', function () {
    $result = GoalProgress::calculate(0, 90000, CarbonImmutable::parse('2025-06-01'), CarbonImmutable::parse('2026-01-05'));

    expect($result['monthly_needed'])->toBe(90000);
});

it('omite monthly_needed quando não há data', function () {
    $result = GoalProgress::calculate(50000, 100000, null, CarbonImmutable::parse('2026-01-05'));

    expect($result)->not->toHaveKey('monthly_needed');
});

it('zera o percentual quando o progresso é negativo, sem afetar o restante', function () {
    $result = GoalProgress::calculate(-20000, 100000, null, CarbonImmutable::now());

    expect($result['progress'])->toBe(-20000)
        ->and($result['percent'])->toBe(0)
        ->and($result['remaining'])->toBe(120000);
});

it('percentual arredonda para baixo: 100 só quando de fato bate o alvo', function () {
    // 20000 / 30000 = 66,67%: arredondar para cima daria 67.
    $result = GoalProgress::calculate(20000, 30000, null, CarbonImmutable::now());

    expect($result['percent'])->toBe(66);
});

it('limita o percentual a 100 quando o progresso passa do alvo', function () {
    $result = GoalProgress::calculate(150000, 100000, null, CarbonImmutable::now());

    expect($result['percent'])->toBe(100)
        ->and($result['remaining'])->toBe(0);
});

it('meta já atingida: restante e ritmo mensal zerados', function () {
    $result = GoalProgress::calculate(100000, 100000, CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-01-05'));

    expect($result['remaining'])->toBe(0)
        ->and($result['monthly_needed'])->toBe(0)
        ->and(GoalProgress::isAchieved(100000, 100000))->toBeTrue();
});

it('não está atingida enquanto o progresso é menor que o alvo', function () {
    expect(GoalProgress::isAchieved(99999, 100000))->toBeFalse();
});
