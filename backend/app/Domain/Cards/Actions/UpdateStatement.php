<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Datas reais da fatura (o banco fechou antes/depois do nominal) e o total
 * informado pelo banco. Não move lançamentos: o usuário move os que quiser.
 *
 * Mantém as faturas do cartão ordenadas e sem sobreposição: a nova
 * closing_date/due_date precisa ficar estritamente entre a da fatura vizinha
 * anterior e a da próxima (vizinhas por closing_date, não pela data nova —
 * senão a fatura poderia "pular" por cima de uma vizinha). A trava na linha
 * da conta serializa edições concorrentes do mesmo cartão (a mesma trava que
 * StatementResolver::forDate usa para criar fatura nova).
 */
final class UpdateStatement
{
    private const MAX_SPAN_DAYS = 40;

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     */
    public function handle(CardStatement $statement, array $input): CardStatement
    {
        return DB::transaction(function () use ($statement, $input) {
            Account::query()->whereKey($statement->account_id)->lockForUpdate()->firstOrFail();

            $closing = array_key_exists('closing_date', $input)
                ? CarbonImmutable::parse($input['closing_date'])->startOfDay()
                : $statement->closing_date;
            $due = array_key_exists('due_date', $input)
                ? CarbonImmutable::parse($input['due_date'])->startOfDay()
                : $statement->due_date;

            $this->ensureOrdered($statement, $closing, $due);

            $statement->update($input);

            return CardStatement::query()->withTotals()->findOrFail($statement->id);
        });
    }

    private function ensureOrdered(CardStatement $statement, CarbonImmutable $closing, CarbonImmutable $due): void
    {
        $others = CardStatement::query()->where('account_id', $statement->account_id)->where('id', '!=', $statement->id);

        $previous = (clone $others)->where('closing_date', '<', $statement->closing_date)->orderByDesc('closing_date')->first();
        $next = (clone $others)->where('closing_date', '>', $statement->closing_date)->orderBy('closing_date')->first();

        if ($previous !== null && $closing->lessThanOrEqualTo($previous->closing_date)) {
            throw ValidationException::withMessages([
                'closing_date' => 'O fechamento precisa ser depois do fechamento da fatura anterior.',
            ]);
        }

        if ($next !== null && $closing->greaterThanOrEqualTo($next->closing_date)) {
            throw ValidationException::withMessages([
                'closing_date' => 'O fechamento precisa ser antes do fechamento da próxima fatura.',
            ]);
        }

        if ($previous !== null && $due->lessThanOrEqualTo($previous->due_date)) {
            throw ValidationException::withMessages([
                'due_date' => 'O vencimento precisa ser depois do vencimento da fatura anterior.',
            ]);
        }

        if ($next !== null && $due->greaterThanOrEqualTo($next->due_date)) {
            throw ValidationException::withMessages([
                'due_date' => 'O vencimento precisa ser antes do vencimento da próxima fatura.',
            ]);
        }

        if ($closing->diffInDays($due) > self::MAX_SPAN_DAYS) {
            throw ValidationException::withMessages([
                'due_date' => 'O vencimento não pode passar de 40 dias depois do fechamento.',
            ]);
        }
    }
}
