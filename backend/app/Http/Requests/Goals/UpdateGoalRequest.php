<?php

namespace App\Http\Requests\Goals;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Goals\Concerns\ValidatesGoalAccount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * target_date não exige "after:today" aqui: a regra é só na criação (uma
 * meta cujo alvo já passou continua editável, ex.: para adiar a data).
 */
final class UpdateGoalRequest extends ApiRequest
{
    use ValidatesGoalAccount;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'target_amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000000'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty()) {
                $this->validateGoalAccount($validator);
            }
        }];
    }
}
