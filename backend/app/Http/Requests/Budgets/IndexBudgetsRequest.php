<?php

namespace App\Http\Requests\Budgets;

use App\Http\Requests\ApiRequest;
use Carbon\CarbonImmutable;

final class IndexBudgetsRequest extends ApiRequest
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
