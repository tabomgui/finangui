<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Enums\ReportBasis;
use App\Http\Requests\ApiRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class MonthlyEvolutionRequest extends ApiRequest
{
    private const MAX_MONTHS = 24;

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

            $from = $this->from();
            $to = $this->to();

            if ($to->lessThan($from)) {
                $validator->errors()->add('to', 'O fim precisa ser igual ou depois do início.');

                return;
            }

            if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
                $validator->errors()->add('to', 'O período não pode passar de '.self::MAX_MONTHS.' meses.');
            }
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
