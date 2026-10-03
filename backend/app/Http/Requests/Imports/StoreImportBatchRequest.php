<?php

namespace App\Http\Requests\Imports;

use App\Domain\Imports\Enums\ImportFormat;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class StoreImportBatchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            // `mimes` detecta pelo conteúdo (finfo) e não reconhece OFX de forma
            // confiável; `extensions` valida pelo nome do arquivo, o suficiente
            // aqui (o conteúdo em si é validado pelo parser).
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt,ofx'],
            'format' => ['sometimes', Rule::enum(ImportFormat::class)],
        ];
    }
}
