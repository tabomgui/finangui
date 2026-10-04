<?php

namespace App\Domain\Goals\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Goals\Models\Goal;
use Carbon\CarbonImmutable;

/**
 * Progresso de uma meta, sempre derivado (nunca gravado). `calculate()`/
 * `isAchieved()` são puras (só inteiros e datas), pensadas para teste
 * unitário sem banco; `for()`/`achieved()` extraem os números de um Goal.
 *
 * Com conta vinculada, o progresso é o saldo dela (pode ser negativo — vira 0
 * só no `percent`, nunca no `progress`/`remaining` em si); sem conta, a soma
 * dos aportes. Pré-carregar a conta com `withBalance()` (ver
 * App\Domain\Accounts\Models\Account::scopeWithBalance()) antes de chamar
 * `for()`/`achieved()` evita uma consulta por meta ao listar; sem isso, cada
 * chamada busca sob demanda (uso avulso, ex.: depois de criar/editar uma
 * meta ou um aporte único).
 */
final class GoalProgress
{
    /**
     * @return array{progress: int, remaining: int, percent: int, monthly_needed?: int}
     */
    public static function calculate(int $progress, int $targetAmount, ?CarbonImmutable $targetDate, CarbonImmutable $now): array
    {
        $remaining = (int) max($targetAmount - $progress, 0);
        $percent = $targetAmount > 0 ? (int) min(100, max(0, round($progress * 100 / $targetAmount))) : 0;

        $result = [
            'progress' => $progress,
            'remaining' => $remaining,
            'percent' => $percent,
        ];

        if ($targetDate !== null) {
            // Meses do mês atual ao mês do alvo, mínimo 1 (mesmo mês ou alvo no passado).
            $months = max(1, $now->startOfMonth()->diffInMonths($targetDate->startOfMonth()));
            $result['monthly_needed'] = (int) ceil($remaining / $months);
        }

        return $result;
    }

    public static function isAchieved(int $progress, int $targetAmount): bool
    {
        return $progress >= $targetAmount;
    }

    /**
     * @return array{progress: int, remaining: int, percent: int, monthly_needed?: int}
     */
    public static function for(Goal $goal, ?CarbonImmutable $now = null): array
    {
        return self::calculate(self::progressOf($goal), $goal->target_amount->cents, $goal->target_date, $now ?? CarbonImmutable::now());
    }

    public static function achieved(Goal $goal): bool
    {
        return self::isAchieved(self::progressOf($goal), $goal->target_amount->cents);
    }

    private static function progressOf(Goal $goal): int
    {
        if ($goal->account_id !== null) {
            $account = $goal->relationLoaded('account') && $goal->account?->hasBalance()
                ? $goal->account
                : Account::query()->withBalance()->find($goal->account_id);

            return $account?->balance()->cents ?? 0;
        }

        return array_key_exists('contributions_sum_amount', $goal->getAttributes())
            ? (int) ($goal->getAttribute('contributions_sum_amount') ?? 0)
            : (int) $goal->contributions()->sum('amount');
    }
}
