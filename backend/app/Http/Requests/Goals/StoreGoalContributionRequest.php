<?php

namespace App\Http\Requests\Goals;

use App\Http\Requests\ApiRequest;

final class StoreGoalContributionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Negativo = retirada; nunca zero. Se a meta tem conta vinculada, o
            // aporte é rejeitado como erro de negócio (ver CreateGoalContribution),
            // não aqui: a regra depende do estado da meta, não só do formato do campo.
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000000000000,1000000000000000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:120'],
        ];
    }
}
