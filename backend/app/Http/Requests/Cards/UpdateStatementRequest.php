<?php

namespace App\Http\Requests\Cards;

use App\Domain\Cards\Models\CardStatement;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Checagens de ordem relativa (fechamento/vencimento x vizinhas, limite de 40
 * dias) ficam em App\Domain\Cards\Actions\UpdateStatement, dentro da mesma
 * transação que trava a conta — aqui só o formato dos campos e o conflito
 * óbvio com a própria fatura (closing/due atuais) ou com o due_date de outra.
 */
final class UpdateStatementRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Scramble chama rules() fora de uma request real (sem rota) para gerar a
        // doc da API: $statement precisa ser null-safe, como em UpdateAccountRequest.
        $statement = $this->route('statement');
        $closing = $this->input('closing_date', $statement instanceof CardStatement ? $statement->closing_date->toDateString() : null);
        $due = $this->input('due_date', $statement instanceof CardStatement ? $statement->due_date->toDateString() : null);

        $validDate = fn (mixed $value): bool => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        return [
            'closing_date' => [
                'sometimes', 'date_format:Y-m-d',
                ...($validDate($due) ? ['before:'.$due] : []),
            ],
            'due_date' => [
                'sometimes', 'date_format:Y-m-d',
                ...($validDate($closing) ? ['after:'.$closing] : []),
                $statement instanceof CardStatement
                    ? Rule::unique('card_statements', 'due_date')->where('account_id', $statement->account_id)->ignore($statement->id)
                    : Rule::unique('card_statements', 'due_date'),
            ],
            'reported_total' => ['sometimes', 'nullable', 'integer', 'between:-1000000000000000,1000000000000000'],
        ];
    }
}
