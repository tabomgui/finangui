<?php

namespace App\Domain\Cards\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Data\StatementDates;
use App\Domain\Cards\Errors\NotACreditCard;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Acha (ou cria) a fatura de uma data. Faturas já gravadas têm prioridade
 * sobre as datas nominais do InvoiceCycle: o usuário (ou o banco) pode ter
 * antecipado ou adiado o fechamento. Uma fatura gravada "corresponde" a um
 * ciclo nominal quando o vencimento dela fica a até WINDOW_DAYS dias do
 * vencimento nominal (ciclos consecutivos ficam a 28+ dias de distância).
 */
final class StatementResolver
{
    private const WINDOW_DAYS = 13;

    public function forDate(Account $card, CarbonImmutable $date): CardStatement
    {
        return DB::transaction(function () use ($card, $date) {
            // Serializa a criação de faturas do mesmo cartão.
            Account::query()->whereKey($card->id)->lockForUpdate()->first();

            $statement = $this->locate($card, $date);
            if (! $statement->exists) {
                $statement->save();
            }

            return $statement;
        });
    }

    /**
     * Como forDate, mas sem gravar: a fatura devolvida pode ser nova (exists = false).
     */
    public function locate(Account $card, CarbonImmutable $date): CardStatement
    {
        [$closingDay, $dueDay] = $this->days($card);
        $date = $date->startOfDay();
        $nominal = InvoiceCycle::forPurchase($date, $closingDay, $dueDay);

        // Fechamento real adiado: a fatura do ciclo anterior ainda pode estar aberta na data.
        $previous = $this->near($card, InvoiceCycle::previous($nominal, $closingDay, $dueDay));
        if ($previous !== null && $date->lessThan($previous->closing_date)) {
            return $previous;
        }

        $cycle = $nominal;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $existing = $this->near($card, $cycle);

            if ($existing === null) {
                return new CardStatement([
                    'user_id' => $card->user_id,
                    'account_id' => $card->id,
                    'closing_date' => $cycle->closingDate,
                    'due_date' => $cycle->dueDate,
                ]);
            }
            if ($date->lessThan($existing->closing_date)) {
                return $existing;
            }

            // Fechamento real antecipado: a data já passou do fechamento desta fatura.
            $cycle = InvoiceCycle::next($cycle, $closingDay, $dueDay);
        }

        throw new LogicException("Não foi possível resolver a fatura de {$date->toDateString()} do cartão {$card->id}.");
    }

    /**
     * Fatura que um pagamento na data quita: a mais recente já fechada; sem
     * nenhuma, a fatura da própria data.
     */
    public function forPayment(Account $card, CarbonImmutable $date): CardStatement
    {
        $this->days($card);

        return CardStatement::query()
            ->where('account_id', $card->id)
            ->where('closing_date', '<=', $date->toDateString())
            ->orderByDesc('closing_date')
            ->first() ?? $this->forDate($card, $date);
    }

    public function next(Account $card, CardStatement $statement): CardStatement
    {
        // Compra no dia do fechamento vai para a próxima fatura.
        return $this->forDate($card, $statement->closing_date);
    }

    private function near(Account $card, StatementDates $cycle): ?CardStatement
    {
        return CardStatement::query()
            ->where('account_id', $card->id)
            ->whereBetween('due_date', [
                $cycle->dueDate->subDays(self::WINDOW_DAYS)->toDateString(),
                $cycle->dueDate->addDays(self::WINDOW_DAYS)->toDateString(),
            ])
            ->orderBy('due_date')
            ->first();
    }

    /**
     * @return array{int, int}
     *
     * @throws NotACreditCard
     */
    private function days(Account $card): array
    {
        if (! $card->isCreditCard() || $card->closing_day === null || $card->due_day === null) {
            throw new NotACreditCard;
        }

        return [$card->closing_day, $card->due_day];
    }
}
