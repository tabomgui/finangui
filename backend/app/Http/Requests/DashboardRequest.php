<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;

final class DashboardRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'date_format:Y-m'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now()->toDateString()],
        ];
    }

    public function month(): CarbonImmutable
    {
        $month = $this->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $this->string('month')->value())
            : null;

        return $month ?: CarbonImmutable::now()->startOfMonth();
    }

    /**
     * Dia do saldo, independente do mês (as setas de mês nunca mudam o dia
     * escolhido). Informado explicitamente, ou o padrão por mês: hoje no
     * mês atual, fim do mês nos meses passados, hoje nos futuros — não
     * existe saldo real futuro.
     */
    public function balanceDate(CarbonImmutable $month): CarbonImmutable
    {
        if ($this->filled('date')) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('date')->value());
        }

        $now = CarbonImmutable::now();

        if ($month->isSameMonth($now)) {
            return $now;
        }

        return $month->lessThan($now->startOfMonth()) ? $month->endOfMonth() : $now;
    }
}
