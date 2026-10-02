<?php

namespace App\Domain\Cards\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Errors\NotACreditCard;
use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Acha (ou cria) a fatura de uma data, ancorado nos fechamentos já gravados:
 * o período de uma fatura é (fechamento anterior, próprio fechamento] —
 * compra no dia do fechamento vai para a próxima fatura. Faturas já gravadas
 * têm prioridade sobre as datas nominais do InvoiceCycle (o usuário ou o
 * banco pode ter antecipado ou adiado o fechamento); o ciclo nominal só
 * entra em jogo quando não há fatura gravada que já cubra a data — aí ele diz
 * qual é o próximo fechamento nominal a criar. WINDOW_DAYS decide quando uma
 * fatura gravada perto de um fechamento nominal "é" aquele ciclo (ciclos
 * consecutivos ficam a 28+ dias de distância, então uma folga bem menor não
 * os confunde).
 */
final class StatementResolver
{
    private const WINDOW_DAYS = 13;

    public function forDate(Account $card, CarbonImmutable $date): CardStatement
    {
        return DB::transaction(function () use ($card, $date) {
            // Serializa a criação de faturas do mesmo cartão; relê a linha
            // travada porque closing_day/due_day podem ter sido editados.
            $locked = Account::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();

            $statement = $this->locate($locked, $date);
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
        $cycle = InvoiceCycle::forPurchase($date, $closingDay, $dueDay);

        // Fechamento real adiado: uma fatura já fechou perto o bastante do
        // ciclo nominal, antes (ou no) da data — esse ciclo nominal já
        // passou, a fatura da data é a seguinte.
        $previousClosed = CardStatement::query()
            ->where('account_id', $card->id)
            ->where('closing_date', '<=', $date->toDateString())
            ->orderByDesc('closing_date')
            ->first();

        if ($previousClosed !== null && $previousClosed->closing_date->greaterThanOrEqualTo($cycle->closingDate->subDays(self::WINDOW_DAYS))) {
            $cycle = InvoiceCycle::next($cycle, $closingDay, $dueDay);
        }

        // A fatura que contém a data é a primeira já fechada depois dela — a
        // menos que essa fatura pertença a um ciclo bem mais adiante (fatura
        // futura já criada, com um vão de ciclos vazios até a data).
        $following = CardStatement::query()
            ->where('account_id', $card->id)
            ->where('closing_date', '>', $date->toDateString())
            ->orderBy('closing_date')
            ->first();

        if ($following !== null && $following->closing_date->lessThanOrEqualTo($cycle->closingDate->addDays(self::WINDOW_DAYS))) {
            return $following;
        }

        // Protege contra colisão de due_date com uma fatura existente de
        // outro ciclo (ex.: fechamento editado bem antes da janela).
        for ($attempt = 0; $this->dueDateTaken($card, $cycle->dueDate); $attempt++) {
            if ($attempt >= 12) {
                throw new LogicException("Não foi possível resolver a fatura de {$date->toDateString()} do cartão {$card->id}.");
            }

            $cycle = InvoiceCycle::next($cycle, $closingDay, $dueDay);
        }

        // Uma fatura nova nunca pode fechar depois (ou no mesmo dia) de uma
        // fatura existente mais adiante: a volta por colisão de due_date
        // pode ter empurrado o ciclo além dela.
        if ($following !== null && $cycle->closingDate->greaterThanOrEqualTo($following->closing_date)) {
            return $following;
        }

        return new CardStatement([
            'user_id' => $card->user_id,
            'account_id' => $card->id,
            'closing_date' => $cycle->closingDate,
            'due_date' => $cycle->dueDate,
        ]);
    }

    /**
     * Fatura que um pagamento na data quita: a mais recente já fechada até a
     * data; sem fatura fechada perto da data, liga à última fechada, por mais
     * antiga que seja; sem nenhuma fatura fechada, cria a fatura da própria
     * data.
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
        if ($statement->account_id !== $card->id) {
            throw new LogicException("A fatura {$statement->id} não pertence ao cartão {$card->id}.");
        }

        // Compra no dia do fechamento vai para a próxima fatura.
        return $this->forDate($card, $statement->closing_date);
    }

    private function dueDateTaken(Account $card, CarbonImmutable $dueDate): bool
    {
        return CardStatement::query()
            ->where('account_id', $card->id)
            ->where('due_date', $dueDate->toDateString())
            ->exists();
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
