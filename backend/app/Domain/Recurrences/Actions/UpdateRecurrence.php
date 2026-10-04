<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campos simples (conta, descrição, valor, categoria, match_pattern)
 * propagam para as ocorrências previstas com data >= hoje. Mudar o
 * calendário (frequency, interval, day_of_month, starts_on, ends_on) exclui
 * as previstas com recurrence_date >= hoje - 5 (a mesma janela em que um
 * lançamento real ainda pode casar, evitando duas ocorrências do mesmo
 * período) e as que ficaram fora do novo intervalo (antes do novo starts_on
 * ou depois do novo ends_on), zera generated_until para hoje - 6 e gera de
 * novo. Pausar (is_active: false) faz a mesma limpeza; reativar avança
 * generated_until até pelo menos hoje - 6 e gera de novo a partir de hoje.
 * direction é imutável depois de criada — o request rejeita esse campo.
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
            $previousAccountId = $recurrence->account_id;
            $wasActive = $recurrence->is_active;
            $calendarChanged = $this->changed($recurrence, $input, self::CALENDAR_FIELDS);
            $simpleChanged = $this->changed($recurrence, $input, self::SIMPLE_FIELDS);

            $recurrence->fill($input);
            self::normalizeDayOfMonth($recurrence);
            $recurrence->save();

            $today = CarbonImmutable::today();
            $activeChanged = $wasActive !== $recurrence->is_active;

            if ($calendarChanged || ($activeChanged && ! $recurrence->is_active)) {
                $this->deleteAffectedProjected($recurrence, $today);
                $recurrence->generated_until = $today->subDays(6);
                $recurrence->save();
                CardStatement::pruneEmptyFuture($recurrence->account_id);
            } elseif ($activeChanged && $recurrence->is_active) {
                $recurrence->generated_until = self::later($recurrence->generated_until ?? $today->subDays(6), $today->subDays(6));
                $recurrence->save();
            }

            if ($calendarChanged || $activeChanged) {
                // No-op quando a recorrência está inativa (pausar não gera nada).
                $this->generateOccurrences->handle($recurrence, $today);
            } elseif ($simpleChanged) {
                $this->propagateSimpleFields($recurrence, $today);

                if ($recurrence->account_id !== $previousAccountId) {
                    CardStatement::pruneEmptyFuture($previousAccountId);
                }
            }

            return $recurrence->load(['account', 'category'])->loadNextDate();
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

    /**
     * day_of_month só existe para frequência mensal; nela, sem valor
     * explícito, o padrão é o dia de starts_on (mesma regra da criação).
     */
    private static function normalizeDayOfMonth(Recurrence $recurrence): void
    {
        if ($recurrence->frequency !== Frequency::Monthly) {
            $recurrence->day_of_month = null;

            return;
        }

        $recurrence->day_of_month ??= $recurrence->starts_on->day;
    }

    /**
     * Previstas afetadas por uma mudança de calendário ou pausa: as de
     * sempre que vida (>= hoje - 5, evita duas ocorrências do mesmo período
     * quando a geração for refeita) e as que o novo intervalo não cobre
     * mais (antes do novo starts_on ou depois do novo ends_on), mesmo que
     * já antigas.
     */
    private function deleteAffectedProjected(Recurrence $recurrence, CarbonImmutable $today): void
    {
        $threshold = $today->subDays(5)->toDateString();

        Transaction::query()
            ->where('recurrence_id', $recurrence->id)
            ->where('status', TransactionStatus::Projected->value)
            ->where(function (Builder $query) use ($recurrence, $threshold) {
                $query->where('recurrence_date', '>=', $threshold)
                    ->orWhere('recurrence_date', '<', $recurrence->starts_on->toDateString());

                if ($recurrence->ends_on !== null) {
                    $query->orWhere('recurrence_date', '>', $recurrence->ends_on->toDateString());
                }
            })
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
