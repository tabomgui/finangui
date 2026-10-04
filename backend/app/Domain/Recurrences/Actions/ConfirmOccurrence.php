<?php

namespace App\Domain\Recurrences\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Recurrences\Errors\OccurrenceNotProjected;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Confirma uma ocorrência ainda não confirmada
 * (Transaction::isUnconfirmedOccurrence(), "aconteceu"): vira posted, com
 * valor e data opcionais no corpo (padrão os previstos). Mantém
 * recurrence_id, recurrence_date, descrição e categoria como estavam. Relê
 * a transação sob lockForUpdate dentro da transação: evita confirmar a
 * mesma ocorrência duas vezes em paralelo (ex.: duplo clique).
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
        return DB::transaction(function () use ($transaction, $input) {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isUnconfirmedOccurrence()) {
                throw new OccurrenceNotProjected;
            }

            if (array_key_exists('amount', $input)) {
                $locked->amount = Money::cents((int) $input['amount']);
            }

            if (array_key_exists('date', $input)) {
                $locked->date = CarbonImmutable::parse($input['date']);
                $this->assignStatement->handle($locked);
            }

            $locked->status = TransactionStatus::Posted;
            $locked->save();

            return $locked->load(['account', 'category.parent', 'tags', 'recurrence']);
        });
    }
}
