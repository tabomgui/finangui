<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Recurrences\Support\RecurrenceSchedule;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gera as transações previstas de uma recorrência, de max(starts_on,
 * generated_until + 1) até o fim do próximo mês (ou ends_on, se antes).
 * Idempotente: generated_until avança ao fim, então uma data já gerada nunca
 * é reconsiderada, mesmo que a ocorrência tenha sido excluída depois. Uma
 * data pulada (skipped_dates) nunca volta, mesmo com generated_until
 * reaberto por uma mudança de calendário. Conta de cartão nunca ganha
 * prevista com data anterior a hoje (não reabre fatura já fechada). Uma
 * recorrência inativa ou de conta arquivada não gera nada.
 *
 * Roda dentro de uma transação com a recorrência relida sob lockForUpdate
 * (is_active e generated_until podem ter mudado desde que o chamador leu o
 * model) e insere com insertOrIgnore: duas chamadas concorrentes para a
 * mesma recorrência serializam no lock, e a unique (recurrence_id,
 * recurrence_date) é a rede de segurança caso ainda assim colidam.
 */
final class GenerateOccurrences
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    public function handle(Recurrence $recurrence, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        $count = DB::transaction(function () use ($recurrence, $today) {
            /** @var Recurrence|null $locked */
            $locked = Recurrence::query()->whereKey($recurrence->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->is_active) {
                return 0;
            }

            $account = Account::query()->find($locked->account_id);

            if ($account === null || $account->is_archived) {
                return 0;
            }

            $from = $locked->generated_until !== null
                ? self::later($locked->starts_on, $locked->generated_until->addDay())
                : $locked->starts_on;

            $limit = $today->addMonthNoOverflow()->endOfMonth()->startOfDay();
            $to = $locked->ends_on !== null ? self::earlier($limit, $locked->ends_on) : $limit;

            if ($from->greaterThan($to)) {
                return 0;
            }

            $dates = RecurrenceSchedule::dates($locked, $from, $to);
            $skippedDates = $locked->skipped_dates;

            $rows = [];

            foreach ($dates as $date) {
                if (in_array($date, $skippedDates, true)) {
                    continue;
                }

                if ($account->isCreditCard() && CarbonImmutable::parse($date)->lessThan($today)) {
                    continue;
                }

                $rows[] = $this->buildRow($locked, $account, $date);
            }

            $inserted = $rows === [] ? 0 : Transaction::query()->insertOrIgnore($rows);

            $locked->generated_until = $to;
            $locked->save();

            return $inserted;
        });

        $recurrence->refresh();

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Recurrence $recurrence, Account $account, string $date): array
    {
        $transaction = new Transaction([
            'user_id' => $recurrence->user_id,
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

        // Linha crua para insertOrIgnore: os casts acima (Money, enums,
        // datas) já produziram os valores prontos para a coluna em
        // getAttributes(); description_key e os timestamps não passam pelo
        // hook de Transaction::booted() (insertOrIgnore não dispara eventos
        // de model), por isso entram à mão aqui.
        $now = CarbonImmutable::now();

        return [
            ...$this->withStatement($transaction),
            'description_key' => TextNormalizer::key($recurrence->description),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function withStatement(Transaction $transaction): array
    {
        $this->assignStatement->handle($transaction);

        return $transaction->getAttributes();
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
