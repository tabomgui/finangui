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
        return ['month' => ['sometimes', 'date_format:Y-m']];
    }

    public function month(): CarbonImmutable
    {
        $month = $this->filled('month')
            ? CarbonImmutable::createFromFormat('!Y-m', $this->string('month')->value())
            : null;

        return $month ?: CarbonImmutable::now()->startOfMonth();
    }
}
