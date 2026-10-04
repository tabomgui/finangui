<?php

namespace App\Domain\Recurrences\Support;

use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Models\Recurrence;
use Carbon\CarbonImmutable;

/**
 * Datas das ocorrências de uma recorrência, puro (sem banco). Semanal:
 * starts_on + k·7·interval dias. Mensal: a cada interval meses a partir do
 * mês de starts_on, no day_of_month (padrão o dia de starts_on), limitado ao
 * último dia do mês. Anual: a cada interval anos no mesmo mês/dia de
 * starts_on (29/02 vira 28/02 em ano não bissexto). Nunca antes de starts_on,
 * nunca depois de ends_on.
 */
final class RecurrenceSchedule
{
    /**
     * @param  Recurrence|array{frequency: Frequency|string, interval: int, day_of_month?: int|null, starts_on: CarbonImmutable|string, ends_on?: CarbonImmutable|string|null}  $spec
     * @return list<string>
     */
    public static function dates(Recurrence|array $spec, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $frequency = self::frequency($spec);
        $interval = (int) self::value($spec, 'interval');
        $startsOn = self::toDate(self::value($spec, 'starts_on'));
        $endsOn = self::toNullableDate(self::value($spec, 'ends_on'));
        $dayOfMonth = (int) (self::value($spec, 'day_of_month') ?? $startsOn->day);

        $from = $from->greaterThan($startsOn) ? $from : $startsOn;
        $to = $endsOn !== null && $endsOn->lessThan($to) ? $endsOn : $to;

        if ($from->greaterThan($to)) {
            return [];
        }

        return match ($frequency) {
            Frequency::Weekly => self::weekly($startsOn, $interval, $from, $to),
            Frequency::Monthly => self::monthly($startsOn, $interval, $dayOfMonth, $from, $to),
            Frequency::Yearly => self::yearly($startsOn, $interval, $from, $to),
        };
    }

    /**
     * @return list<string>
     */
    private static function weekly(CarbonImmutable $startsOn, int $interval, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $step = 7 * $interval;
        $k = (int) max(0, ceil($startsOn->diffInDays($from) / $step));

        $dates = [];
        while (true) {
            $date = $startsOn->addDays($k * $step);

            if ($date->greaterThan($to)) {
                break;
            }

            $dates[] = $date->toDateString();
            $k++;
        }

        return $dates;
    }

    /**
     * @return list<string>
     */
    private static function monthly(CarbonImmutable $startsOn, int $interval, int $dayOfMonth, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $startMonth = $startsOn->startOfMonth();
        $diffMonths = $startMonth->diffInMonths($from->startOfMonth());
        $k = (int) max(0, ceil($diffMonths / $interval));

        $dates = [];
        while (true) {
            $month = $startMonth->addMonthsNoOverflow($k * $interval);
            $date = $month->setDay(min($dayOfMonth, $month->daysInMonth));

            if ($date->greaterThan($to)) {
                break;
            }

            if ($date->greaterThanOrEqualTo($from)) {
                $dates[] = $date->toDateString();
            }

            $k++;
        }

        return $dates;
    }

    /**
     * @return list<string>
     */
    private static function yearly(CarbonImmutable $startsOn, int $interval, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $k = (int) max(0, ceil($startsOn->diffInYears($from) / $interval));

        $dates = [];
        while (true) {
            $date = self::yearlyOccurrence($startsOn, $interval, $k);

            if ($date->greaterThan($to)) {
                break;
            }

            if ($date->greaterThanOrEqualTo($from)) {
                $dates[] = $date->toDateString();
            }

            $k++;
        }

        return $dates;
    }

    private static function yearlyOccurrence(CarbonImmutable $startsOn, int $interval, int $k): CarbonImmutable
    {
        $year = $startsOn->year + $interval * $k;
        $day = $startsOn->month === 2 && $startsOn->day === 29 && ! CarbonImmutable::create($year)->isLeapYear()
            ? 28
            : $startsOn->day;

        return $startsOn->setDate($year, $startsOn->month, $day)->startOfDay();
    }

    /**
     * @param  Recurrence|array<string, mixed>  $spec
     */
    private static function frequency(Recurrence|array $spec): Frequency
    {
        $value = self::value($spec, 'frequency');

        return $value instanceof Frequency ? $value : Frequency::from($value);
    }

    private static function toDate(mixed $value): CarbonImmutable
    {
        return $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value);
    }

    private static function toNullableDate(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : self::toDate($value);
    }

    /**
     * @param  Recurrence|array<string, mixed>  $spec
     */
    private static function value(Recurrence|array $spec, string $key): mixed
    {
        return is_array($spec) ? ($spec[$key] ?? null) : $spec->{$key};
    }
}
