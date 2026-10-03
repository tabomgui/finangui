<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\RuleDefinitionCleaner;

final class UpdateRule
{
    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     */
    public function handle(Rule $rule, array $input): Rule
    {
        if (array_key_exists('conditions', $input)) {
            $input['conditions'] = RuleDefinitionCleaner::conditions($input['conditions']);
        }

        if (array_key_exists('actions', $input)) {
            $input['actions'] = RuleDefinitionCleaner::actions($input['actions']);
        }

        $rule->update($input);

        return $rule;
    }
}
