<?php

namespace App\Domain\Cards\Support;

use App\Domain\Cards\Data\StatementDates;
use Carbon\CarbonImmutable;

/**
 * Datas nominais de fatura a partir dos dias de fechamento e vencimento do
 * cartão. Puro: não consulta o banco. As datas reais (editadas pelo usuário ou
 * vindas do banco) ficam em card_statements e têm prioridade — ver
 * StatementResolver.
 */
final class InvoiceCycle
{
    /**
     * Compra no dia do fechamento vai para a próxima fatura. Dia além do fim do
     * mês é limitado ao último dia. Vencimento é a próxima ocorrência de
     * $dueDay estritamente depois do fechamento.
     */
    public static function forPurchase(CarbonImmutable $date, int $closingDay, int $dueDay): StatementDates
    {
        $date = $date->startOfDay();
        $closing = self::onDay($date->startOfMonth(), $closingDay);

        if ($date->greaterThanOrEqualTo($closing)) {
            $closing = self::onDay($date->startOfMonth()->addMonthNoOverflow(), $closingDay);
        }

        return new StatementDates($closing, self::dueAfter($closing, $dueDay));
    }

    public static function next(StatementDates $dates, int $closingDay, int $dueDay): StatementDates
    {
        $closing = self::onDay($dates->closingDate->startOfMonth()->addMonthNoOverflow(), $closingDay);

        return new StatementDates($closing, self::dueAfter($closing, $dueDay));
    }

    public static function previous(StatementDates $dates, int $closingDay, int $dueDay): StatementDates
    {
        $closing = self::onDay($dates->closingDate->startOfMonth()->subMonthNoOverflow(), $closingDay);

        return new StatementDates($closing, self::dueAfter($closing, $dueDay));
    }

    private static function dueAfter(CarbonImmutable $closing, int $dueDay): CarbonImmutable
    {
        $due = self::onDay($closing->startOfMonth(), $dueDay);

        return $due->greaterThan($closing) ? $due : self::onDay($closing->startOfMonth()->addMonthNoOverflow(), $dueDay);
    }

    private static function onDay(CarbonImmutable $monthStart, int $day): CarbonImmutable
    {
        return $monthStart->setDay(min($day, $monthStart->daysInMonth));
    }
}
