<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Errors\RecurrenceTransactionIneligible;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cria o modelo e já gera as ocorrências previstas (sem esperar o job
 * diário). Com transaction_id: a transação (lançada, não transferência, não
 * parcela, sem recorrência) vira a primeira ocorrência — starts_on e
 * generated_until viram a data dela, e os campos do modelo não informados no
 * corpo são lidos dela.
 */
final class CreateRecurrence
{
    public function __construct(private readonly GenerateOccurrences $generateOccurrences) {}

    /**
     * @param  array<string, mixed>  $input  dados já validados
     *
     * @throws RecurrenceTransactionIneligible
     */
    public function handle(array $input): Recurrence
    {
        $transaction = null;

        if (array_key_exists('transaction_id', $input) && $input['transaction_id'] !== null) {
            $transaction = Transaction::query()->findOrFail((int) $input['transaction_id']);
            self::ensureEligible($transaction);
        }

        unset($input['transaction_id']);

        if ($transaction !== null) {
            $input['account_id'] ??= $transaction->account_id;
            $input['description'] ??= $transaction->description;
            $input['amount'] ??= $transaction->amount->cents;
            $input['direction'] ??= $transaction->direction->value;
            if (! array_key_exists('category_id', $input)) {
                $input['category_id'] = $transaction->category_id;
            }
            $input['starts_on'] = $transaction->date->toDateString();
        }

        if (($input['frequency'] ?? null) === Frequency::Monthly->value && empty($input['day_of_month'])) {
            $input['day_of_month'] = CarbonImmutable::parse($input['starts_on'])->day;
        }

        return DB::transaction(function () use ($input, $transaction) {
            $recurrence = Recurrence::create($input);

            if ($transaction !== null) {
                $recurrence->generated_until = $recurrence->starts_on;
                $recurrence->save();

                $transaction->update([
                    'recurrence_id' => $recurrence->id,
                    'recurrence_date' => $recurrence->starts_on,
                ]);
            }

            $this->generateOccurrences->handle($recurrence);

            return $recurrence->load(['account', 'category']);
        });
    }

    /**
     * @throws RecurrenceTransactionIneligible
     */
    private static function ensureEligible(Transaction $transaction): void
    {
        $eligible = $transaction->status === TransactionStatus::Posted
            && $transaction->transfer_id === null
            && $transaction->installment_plan_id === null
            && $transaction->recurrence_id === null;

        if (! $eligible) {
            throw new RecurrenceTransactionIneligible;
        }
    }
}
