<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\ApiRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

final class SpendingBreakdownRequest extends ApiRequest
{
    private const MAX_DAYS = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
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

            if ($from->diffInDays($to) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'O período não pode passar de '.self::MAX_DAYS.' dias.');
            }
        }];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->value());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('to')->value());
    }
}
