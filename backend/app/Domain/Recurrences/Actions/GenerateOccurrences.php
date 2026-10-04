<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Recurrences\Support\RecurrenceSchedule;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Gera as transações previstas de uma recorrência, de max(starts_on,
 * generated_until + 1) até o fim do próximo mês (ou ends_on, se antes).
 * Idempotente: generated_until avança ao fim, então uma data já gerada nunca
 * é reconsiderada, mesmo que a ocorrência tenha sido excluída ou pulada
 * depois (ver ConfirmOccurrence/SkipOccurrence, fora desta tarefa). Uma
 * recorrência inativa não gera nada.
 */
final class GenerateOccurrences
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    public function handle(Recurrence $recurrence, ?CarbonImmutable $today = null): int
    {
        if (! $recurrence->is_active) {
            return 0;
        }

        $today ??= CarbonImmutable::today();

        $from = $recurrence->generated_until !== null
            ? self::later($recurrence->starts_on, $recurrence->generated_until->addDay())
            : $recurrence->starts_on;

        $limit = $today->addMonthNoOverflow()->endOfMonth()->startOfDay();
        $to = $recurrence->ends_on !== null ? self::earlier($limit, $recurrence->ends_on) : $limit;

        if ($from->greaterThan($to)) {
            return 0;
        }

        $dates = RecurrenceSchedule::dates($recurrence, $from, $to);
        $account = Account::query()->findOrFail($recurrence->account_id);

        $count = 0;

        foreach ($dates as $date) {
            $exists = Transaction::query()
                ->where('recurrence_id', $recurrence->id)
                ->where('recurrence_date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            $transaction = new Transaction([
                'account_id' => $recurrence->account_id,
                'date' => $date,
                'amount' => $recurrence->amount,
                'direction' => $recurrence->direction,
                'currency' => $account->currency,
                'description' => $recurrence->description,
                'original_description' => $recurrence->description,
                'category_id' => $recurrence->category_id,
                'categorized_by' => $recurrence->category_id !== null ? 'manual' : null,
                'status' => TransactionStatus::Projected,
                'source' => TransactionSource::Recurrence,
                'recurrence_id' => $recurrence->id,
                'recurrence_date' => $date,
            ]);

            $this->assignStatement->handle($transaction);
            $transaction->save();

            $count++;
        }

        $recurrence->generated_until = $to;
        $recurrence->save();

        return $count;
    }

    private static function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    private static function earlier(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lessThan($b) ? $a : $b;
    }
}
