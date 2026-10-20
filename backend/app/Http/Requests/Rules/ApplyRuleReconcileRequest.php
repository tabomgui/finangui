<?php

namespace App\Http\Requests\Rules;

use App\Domain\Rules\Enums\ReconcileGroup;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class ApplyRuleReconcileRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Grupos da prévia (GET /rules/{rule}/reconcile) a aplicar.
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['required', 'string', 'distinct', Rule::enum(ReconcileGroup::class)],
        ];
    }

    /**
     * @return list<ReconcileGroup>
     */
    public function groups(): array
    {
        /** @var list<string> $values */
        $values = $this->validated('groups');

        return array_map(ReconcileGroup::from(...), $values);
    }
}
