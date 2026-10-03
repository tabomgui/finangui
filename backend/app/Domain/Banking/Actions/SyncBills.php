<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Cards\Errors\NotACreditCard;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\InvoiceCycle;
use App\Domain\Cards\Support\StatementOrdering;
use Carbon\CarbonImmutable;

/**
 * Segundo passo do sync periódico de um cartão (App\Domain\Banking\Jobs\SyncConnection):
 * upsert de cada fatura informada pelo banco (só Open Finance) por
 * `external_id`. Uma fatura local sem `external_id` e com o mesmo
 * `due_date` da fatura do banco é adotada (ganha o id) em vez de criar uma
 * duplicata. Datas novas só são aplicadas quando mantêm a ordem com as
 * vizinhas e o vão de até 40 dias entre fechamento e vencimento — a mesma
 * validação de App\Domain\Cards\Actions\UpdateStatement, extraída para
 * App\Domain\Cards\Support\StatementOrdering; quando não cabem, a fatura
 * fica com as datas antigas, mas `external_id`/`reported_total` são
 * gravados do mesmo jeito.
 */
final class SyncBills
{
    /**
     * @param  list<ProviderBill>  $bills
     *
     * @throws NotACreditCard
     */
    public function handle(Account $card, array $bills): void
    {
        if (! $card->isCreditCard() || $card->closing_day === null || $card->due_day === null) {
            throw new NotACreditCard;
        }

        foreach ($bills as $bill) {
            $this->applyBill($card, $bill);
        }
    }

    private function applyBill(Account $card, ProviderBill $bill): void
    {
        $due = CarbonImmutable::parse($bill->dueDate)->startOfDay();
        $closing = $bill->closingDate !== null
            ? CarbonImmutable::parse($bill->closingDate)->startOfDay()
            : InvoiceCycle::closingForDueDate($due, (int) $card->closing_day, (int) $card->due_day);

        $statement = CardStatement::query()
            ->where('account_id', $card->id)
            ->where('external_id', $bill->id)
            ->first();

        $statement ??= CardStatement::query()
            ->where('account_id', $card->id)
            ->where('due_date', $due->toDateString())
            ->whereNull('external_id')
            ->first();

        if ($statement === null) {
            CardStatement::create([
                'user_id' => $card->user_id,
                'account_id' => $card->id,
                'closing_date' => $closing,
                'due_date' => $due,
                'external_id' => $bill->id,
                'reported_total' => $bill->totalCents,
            ]);

            return;
        }

        $datesChanged = ! $statement->due_date->equalTo($due) || ! $statement->closing_date->equalTo($closing);

        if ($datesChanged) {
            ['previous' => $previous, 'next' => $next] = StatementOrdering::neighbors($statement);

            if (StatementOrdering::fits($previous, $next, $closing, $due)) {
                $statement->closing_date = $closing;
                $statement->due_date = $due;
            }
        }

        $statement->fill(['external_id' => $bill->id, 'reported_total' => $bill->totalCents]);
        $statement->save();
    }
}
