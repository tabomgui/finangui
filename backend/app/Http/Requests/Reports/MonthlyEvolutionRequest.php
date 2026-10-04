<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Enums\ReportBasis;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Reports\Concerns\ValidatesMonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class MonthlyEvolutionRequest extends ApiRequest
{
    use ValidatesMonthRange;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m'],
            'basis' => ['sometimes', Rule::enum(ReportBasis::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateMonthRange($validator, $this->from(), $this->to(), 'to');
        }];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->string('from')->value());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->string('to')->value());
    }

    public function basis(): ReportBasis
    {
        return ReportBasis::from($this->string('basis', ReportBasis::Purchase->value)->value());
    }
}
