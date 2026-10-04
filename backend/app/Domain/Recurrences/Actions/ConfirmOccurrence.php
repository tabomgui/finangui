<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Recurrences\Errors\OccurrenceNotProjected;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Confirma uma ocorrência prevista ("aconteceu"): vira posted, com valor e
 * data opcionais no corpo (padrão os previstos). Mantém recurrence_id,
 * recurrence_date, descrição e categoria como estavam.
 */
final class ConfirmOccurrence
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    /**
     * @param  array{amount?: int, date?: string}  $input  dados já validados
     *
     * @throws OccurrenceNotProjected
     */
    public function handle(Transaction $transaction, array $input): Transaction
    {
        self::ensureProjectedOccurrence($transaction);

        if (array_key_exists('amount', $input)) {
            $transaction->amount = Money::cents((int) $input['amount']);
        }

        if (array_key_exists('date', $input)) {
            $transaction->date = CarbonImmutable::parse($input['date']);
            $this->assignStatement->handle($transaction);
        }

        $transaction->status = TransactionStatus::Posted;
        $transaction->save();

        return $transaction->load(['account', 'category.parent', 'tags', 'recurrence']);
    }

    /**
     * @throws OccurrenceNotProjected
     */
    private static function ensureProjectedOccurrence(Transaction $transaction): void
    {
        if ($transaction->status !== TransactionStatus::Projected || $transaction->recurrence_id === null) {
            throw new OccurrenceNotProjected;
        }
    }
}
