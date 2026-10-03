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

        return new StatementDates($closing, self::dueAfter($closing, $closingDay, $dueDay));
    }

    public static function next(StatementDates $dates, int $closingDay, int $dueDay): StatementDates
    {
        $closing = self::onDay($dates->closingDate->startOfMonth()->addMonthNoOverflow(), $closingDay);

        return new StatementDates($closing, self::dueAfter($closing, $closingDay, $dueDay));
    }

    public static function previous(StatementDates $dates, int $closingDay, int $dueDay): StatementDates
    {
        $closing = self::onDay($dates->closingDate->startOfMonth()->subMonthNoOverflow(), $closingDay);

        return new StatementDates($closing, self::dueAfter($closing, $closingDay, $dueDay));
    }

    /**
     * Ciclo nominal de fechamento no mês de $month (fechamento = $closingDay
     * clampado nesse mês). Usado para reposicionar faturas futuras quando
     * closing_day/due_day do cartão mudam (ver UpdateAccount).
     */
    public static function forClosingMonth(CarbonImmutable $month, int $closingDay, int $dueDay): StatementDates
    {
        $closing = self::onDay($month->startOfMonth(), $closingDay);

        return new StatementDates($closing, self::dueAfter($closing, $closingDay, $dueDay));
    }

    /**
     * Vencimento nominal depois do fechamento. Quando o vencimento é pensado
     * para o mesmo mês do fechamento ($dueDay > $closingDay) mas o fim de mês
     * curto limita os dois ao mesmo dia (ex.: fechamento 28, vencimento 30:
     * fevereiro fecha em 28 e "venceria" em 28 também, colidindo com o
     * vencimento de março que cai em 30), o vencimento passa a ser o dia
     * seguinte ao fechamento. Fora desse caso, vale a regra antiga: o dia
     * nominal no mês do fechamento se ainda cair depois dele, senão no mês
     * seguinte.
     */
    private static function dueAfter(CarbonImmutable $closing, int $closingDay, int $dueDay): CarbonImmutable
    {
        $due = self::onDay($closing->startOfMonth(), $dueDay);

        if ($dueDay > $closingDay && $due->lessThanOrEqualTo($closing)) {
            return $closing->addDay();
        }

        return $due->greaterThan($closing) ? $due : self::onDay($closing->startOfMonth()->addMonthNoOverflow(), $dueDay);
    }

    private static function onDay(CarbonImmutable $monthStart, int $day): CarbonImmutable
    {
        return $monthStart->setDay(min($day, $monthStart->daysInMonth));
    }

    /**
     * Fechamento nominal que produziria $dueDate como vencimento — o
     * inverso de forPurchase()/dueAfter(): usado quando o banco informa uma
     * fatura (sync bancário, ver App\Domain\Banking\Actions\SyncBills) sem
     * dizer o fechamento. Tenta o ciclo do mês do próprio vencimento e,
     * sem bater, o do mês anterior (o vencimento pode cair no mês seguinte
     * ao fechamento); sem nenhum dos dois reproduzir exatamente o
     * vencimento informado (dias do cartão podem ter mudado desde que o
     * banco gerou essa fatura), fica com a estimativa do mês do próprio
     * vencimento.
     */
    public static function closingForDueDate(CarbonImmutable $dueDate, int $closingDay, int $dueDay): CarbonImmutable
    {
        $dueDate = $dueDate->startOfDay();
        $sameMonth = self::forClosingMonth($dueDate->startOfMonth(), $closingDay, $dueDay);

        if ($sameMonth->dueDate->equalTo($dueDate)) {
            return $sameMonth->closingDate;
        }

        $previousMonth = self::forClosingMonth($dueDate->startOfMonth()->subMonthNoOverflow(), $closingDay, $dueDay);

        return $previousMonth->dueDate->equalTo($dueDate) ? $previousMonth->closingDate : $sameMonth->closingDate;
    }
}
