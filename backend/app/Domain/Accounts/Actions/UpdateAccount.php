<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Errors\AccountTypeLocked;
use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\InvoiceCycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class UpdateAccount
{
    private const CARD_FIELDS = ['credit_limit', 'closing_day', 'due_day', 'last_four'];

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     *
     * @throws AccountTypeLocked
     */
    public function handle(Account $account, array $input): Account
    {
        return DB::transaction(function () use ($account, $input) {
            // Trava a linha pela duração do check+update: sem isso, uma transação
            // criada entre o "existe lançamento?" e o update poderia deixar a conta
            // com histórico de um tipo e cartão/comum do outro.
            $account = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $wasCard = $account->isCreditCard();
            $oldClosingDay = $account->closing_day;
            $oldDueDay = $account->due_day;

            if (array_key_exists('type', $input)) {
                $newType = $input['type'] instanceof AccountType ? $input['type'] : AccountType::from($input['type']);
                $cardChange = ($newType === AccountType::CreditCard) !== $wasCard;

                // Faturas e parcelas dependem do tipo: trocar com histórico deixaria
                // lançamentos de cartão numa conta comum (ou o contrário).
                if ($cardChange && $account->transactions()->exists()) {
                    throw new AccountTypeLocked;
                }
            } else {
                $newType = $account->type;
            }

            if ($newType !== AccountType::CreditCard) {
                foreach (self::CARD_FIELDS as $field) {
                    $input[$field] = null;
                }

                // A troca só é permitida sem lançamentos: qualquer fatura do
                // cartão está necessariamente vazia e vai junto.
                if ($wasCard) {
                    $account->statements()->delete();
                }
            }

            $account->update($input);

            if ($wasCard && $newType === AccountType::CreditCard) {
                $newClosingDay = (int) ($input['closing_day'] ?? $oldClosingDay);
                $newDueDay = (int) ($input['due_day'] ?? $oldDueDay);

                if ($newClosingDay !== $oldClosingDay || $newDueDay !== $oldDueDay) {
                    $this->rescheduleFutureStatements($account, $newClosingDay, $newDueDay);
                }
            }

            return $account;
        });
    }

    /**
     * Dias do cartão mudaram: as faturas futuras foram geradas para o ciclo
     * antigo. Sem lançamento nenhum, a fatura futura não faz falta — exclui.
     * Com lançamento, reagenda para o ciclo nominal novo do mesmo mês do
     * fechamento atual, só quando o novo fechamento ainda está no futuro e o
     * novo fechamento e vencimento ficam estritamente entre os da vizinha
     * anterior e os da seguinte (como elas estão nesse momento do laço —
     * processado em ordem, a anterior já foi decidida, a seguinte ainda não);
     * senão, fica como estava (histórico de pagamento não pode sumir, a ordem
     * cronológica das faturas não pode se inverter, e a próxima compra vai
     * resolver a fatura certa de qualquer forma via StatementResolver).
     */
    private function rescheduleFutureStatements(Account $account, int $closingDay, int $dueDay): void
    {
        $today = CarbonImmutable::today();

        CardStatement::pruneEmptyFuture($account->id);

        $remaining = CardStatement::query()
            ->where('account_id', $account->id)
            ->where('closing_date', '>', $today->toDateString())
            ->orderBy('closing_date')
            ->get();

        foreach ($remaining as $statement) {
            $cycle = InvoiceCycle::forClosingMonth($statement->closing_date->startOfMonth(), $closingDay, $dueDay);

            if ($cycle->closingDate->lessThanOrEqualTo($today)) {
                continue;
            }

            $previous = CardStatement::query()
                ->where('account_id', $account->id)
                ->where('closing_date', '<', $statement->closing_date->toDateString())
                ->orderByDesc('closing_date')
                ->first();

            if ($previous !== null
                && ($cycle->closingDate->lessThanOrEqualTo($previous->closing_date) || $cycle->dueDate->lessThanOrEqualTo($previous->due_date))) {
                continue;
            }

            $next = CardStatement::query()
                ->where('account_id', $account->id)
                ->where('closing_date', '>', $statement->closing_date->toDateString())
                ->orderBy('closing_date')
                ->first();

            if ($next !== null
                && ($cycle->closingDate->greaterThanOrEqualTo($next->closing_date) || $cycle->dueDate->greaterThanOrEqualTo($next->due_date))) {
                continue;
            }

            $statement->update(['closing_date' => $cycle->closingDate, 'due_date' => $cycle->dueDate]);
        }
    }
}
