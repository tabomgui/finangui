<?php

namespace App\Http\Requests\Reports\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * from <= to e no máximo 24 meses (inclusive) — regra compartilhada pelos
 * dois relatórios (MonthlyEvolutionRequest tem um período, CategoryComparisonRequest
 * tem dois, A e B, cada um validado separadamente contra essa mesma regra).
 */
trait ValidatesMonthRange
{
    private const MAX_MONTHS = 24;

    private function validateMonthRange(Validator $validator, CarbonImmutable $from, CarbonImmutable $to, string $field): void
    {
        if ($to->lessThan($from)) {
            $validator->errors()->add($field, 'O fim precisa ser igual ou depois do início.');

            return;
        }

        if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
            $validator->errors()->add($field, 'O período não pode passar de '.self::MAX_MONTHS.' meses.');
        }
    }
}
