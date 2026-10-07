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
     * escolhido, e o mês exibido nunca muda o padrão do dia): informado
     * explicitamente (`date`), ou hoje — sempre, para qualquer mês.
     */
    public function balanceDate(): CarbonImmutable
    {
        if ($this->filled('date')) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('date')->value());
        }

        return CarbonImmutable::now();
    }
}
