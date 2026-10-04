<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Enums\ReportBasis;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Reports\Concerns\ValidatesMonthRange;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CategoryComparisonRequest extends ApiRequest
{
    use ValidatesMonthRange;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'a_from' => ['required', 'date_format:Y-m'],
            'a_to' => ['required', 'date_format:Y-m'],
            'b_from' => ['required', 'date_format:Y-m'],
            'b_to' => ['required', 'date_format:Y-m'],
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

            $this->validateMonthRange($validator, $this->aFrom(), $this->aTo(), 'a_to');
            $this->validateMonthRange($validator, $this->bFrom(), $this->bTo(), 'b_to');
        }];
    }

    public function aFrom(): CarbonImmutable
    {
        return $this->parseMonth('a_from');
    }

    public function aTo(): CarbonImmutable
    {
        return $this->parseMonth('a_to');
    }

    public function bFrom(): CarbonImmutable
    {
        return $this->parseMonth('b_from');
    }

    public function bTo(): CarbonImmutable
    {
        return $this->parseMonth('b_to');
    }

    public function basis(): ReportBasis
    {
        return ReportBasis::from($this->string('basis', ReportBasis::Purchase->value)->value());
    }

    private function parseMonth(string $field): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->string($field)->value());
    }
}
