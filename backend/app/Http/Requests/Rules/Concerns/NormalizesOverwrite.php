<?php

namespace App\Http\Requests\Rules\Concerns;

// Compartilhado entre PreviewRuleRequest e ApplyRuleRequest: "overwrite"
// chega como boolean (JSON) ou, por vezes, como string ("true"/"false");
// normaliza antes de validar, sem abrir mão da regra "boolean" que o
// Scramble usa para documentar o parâmetro.
trait NormalizesOverwrite
{
    protected function prepareForValidation(): void
    {
        if ($this->has('overwrite')) {
            $this->merge([
                'overwrite' => filter_var($this->input('overwrite'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
