<?php

namespace App\Http\Requests\Imports;

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Enums\ImportFormat;
use App\Http\Requests\ApiRequest;
use Closure;
use Illuminate\Validation\Rule;

final class StoreImportBatchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `mimes` detecta pelo conteúdo (finfo) e não reconhece OFX de forma
        // confiável; `extensions` valida pelo nome do arquivo, o suficiente
        // aqui (o conteúdo em si é validado pelo parser) — comentário aqui
        // em vez de junto da regra, pra não entrar na doc do OpenAPI.
        return [
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('user_id', $this->userId()),
                $this->currencyIsBrl(),
            ],
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,txt,ofx'],
            'format' => ['sometimes', Rule::enum(ImportFormat::class)],
        ];
    }

    /**
     * Todos os parsers só entendem valores em reais; importar para uma
     * conta de outra moeda gravaria `amount` em centavos de real dentro de
     * uma conta marcada com outra moeda — rejeitado, em vez de confiar na
     * moeda da conta.
     */
    private function currencyIsBrl(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $currency = Account::query()->whereKey($value)->value('currency');

            if ($currency !== null && $currency !== 'BRL') {
                $fail('Importação só disponível para contas em reais.');
            }
        };
    }
}
