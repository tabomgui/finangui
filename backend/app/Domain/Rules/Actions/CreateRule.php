<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\RuleDefinitionCleaner;

final class CreateRule
{
    /**
     * @param  array<string, mixed>  $input  dados já validados
     */
    public function handle(array $input): Rule
    {
        return Rule::create([
            'name' => $input['name'],
            // Primeira regra do usuário = prioridade 0; senão, uma a mais que a
            // maior prioridade existente (sempre roda por último, por padrão).
            'priority' => ((int) (Rule::query()->max('priority') ?? -1)) + 1,
            'is_active' => $input['is_active'] ?? true,
            'match' => $input['match'],
            'conditions' => RuleDefinitionCleaner::conditions($input['conditions']),
            'actions' => RuleDefinitionCleaner::actions($input['actions']),
        ]);
    }
}
