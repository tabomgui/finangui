<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de todos os FormRequests da API. Autorização é feita pelo auth:sanctum
 * e pelo escopo BelongsToUser; aqui só validamos.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function userId(): int
    {
        return (int) $this->user()?->getAuthIdentifier();
    }
}
