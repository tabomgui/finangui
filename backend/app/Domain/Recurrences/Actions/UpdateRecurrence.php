<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campos simples (conta, descrição, valor, categoria, match_pattern)
 * propagam para as ocorrências previstas com data >= hoje. Mudar o
 * calendário (frequency, interval, day_of_month, starts_on, ends_on) exclui
 * essas previstas, zera generated_until para hoje - 1 e gera de novo.
 * Pausar (is_active: false) exclui as previstas futuras e zera
 * generated_until do mesmo jeito; reativar avança generated_until até pelo
 * menos hoje - 1 e gera de novo a partir de hoje. direction é imutável
 * depois de criada (fora das regras de edição do plano) — o request não
 * aceita esse campo.
 */
final class UpdateRecurrence
{
    /** @var list<string> */
    private const CALENDAR_FIELDS = ['frequency', 'interval', 'day_of_month', 'starts_on', 'ends_on'];

    /** @var list<string> */
    private const SIMPLE_FIELDS = ['account_id', 'description', 'amount', 'category_id', 'match_pattern'];

    public function __construct(
        private readonly GenerateOccurrences $generateOccurrences,
        private readonly AssignStatement $assignStatement,
    ) {}

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     */
    public function handle(Recurrence $recurrence, array $input): Recurrence
    {
        return DB::transaction(function () use ($recurrence, $input) {
            $wasActive = $recurrence->is_active;
            $calendarChanged = $this->changed($recurrence, $input, self::CALENDAR_FIELDS);
            $simpleChanged = $this->changed($recurrence, $input, self::SIMPLE_FIELDS);

            $recurrence->fill($input);
            $recurrence->save();

            $today = CarbonImmutable::today();
            $activeChanged = $wasActive !== $recurrence->is_active;

            if ($calendarChanged || ($activeChanged && ! $recurrence->is_active)) {
                $this->deleteFutureProjected($recurrence, $today);
                $recurrence->generated_until = $today->subDay();
                $recurrence->save();
            } elseif ($activeChanged && $recurrence->is_active) {
                $recurrence->generated_until = self::later($recurrence->generated_until ?? $today->subDay(), $today->subDay());
                $recurrence->save();
            }

            if ($calendarChanged || $activeChanged) {
                // No-op quando a recorrência está inativa (pausar não gera nada).
                $this->generateOccurrences->handle($recurrence, $today);
            } elseif ($simpleChanged) {
                $this->propagateSimpleFields($recurrence, $today);
            }

            return $recurrence->load(['account', 'category']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     */
    private function changed(Recurrence $recurrence, array $input, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $this->currentValue($recurrence, $field)) {
                return true;
            }
        }

        return false;
    }

    private function currentValue(Recurrence $recurrence, string $field): mixed
    {
        return match ($field) {
            'frequency' => $recurrence->frequency->value,
            'starts_on' => $recurrence->starts_on->toDateString(),
            'ends_on' => $recurrence->ends_on?->toDateString(),
            'amount' => $recurrence->amount->cents,
            default => $recurrence->{$field},
        };
    }

    private function deleteFutureProjected(Recurrence $recurrence, CarbonImmutable $today): void
    {
        Transaction::query()
            ->where('recurrence_id', $recurrence->id)
            ->where('status', TransactionStatus::Projected->value)
            ->where('recurrence_date', '>=', $today)
            ->delete();
    }

    private function propagateSimpleFields(Recurrence $recurrence, CarbonImmutable $today): void
    {
        $account = Account::query()->findOrFail($recurrence->account_id);

        /** @var Collection<int, Transaction> $occurrences */
        $occurrences = Transaction::query()
            ->where('recurrence_id', $recurrence->id)
            ->where('status', TransactionStatus::Projected->value)
            ->where('recurrence_date', '>=', $today)
            ->get();

        $occurrences->each(function (Transaction $transaction) use ($recurrence, $account) {
            $transaction->account_id = $recurrence->account_id;
            $transaction->currency = $account->currency;
            $transaction->description = $recurrence->description;
            $transaction->original_description = $recurrence->description;
            $transaction->amount = $recurrence->amount;
            $transaction->category_id = $recurrence->category_id;
            $transaction->categorized_by = $recurrence->category_id !== null ? 'manual' : null;
            $this->assignStatement->handle($transaction);
            $transaction->save();
        });
    }

    private static function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }
}
