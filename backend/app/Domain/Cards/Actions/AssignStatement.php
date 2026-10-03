<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Errors\StatementAccountMismatch;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;

/**
 * Decide a fatura de uma transação (sem salvar): nenhuma fora de cartão; a
 * escolhida, se informada; para a entrada de uma transferência (pagamento), a
 * última fatura fechada; para o resto, a fatura da data.
 */
final class AssignStatement
{
    public function __construct(private readonly StatementResolver $resolver) {}

    /**
     * @throws StatementAccountMismatch
     */
    public function handle(Transaction $transaction, ?int $statementId = null): void
    {
        $account = Account::query()->findOrFail($transaction->account_id);

        if (! $account->isCreditCard()) {
            if ($statementId !== null) {
                throw new StatementAccountMismatch;
            }
            $transaction->statement_id = null;

            return;
        }

        if ($statementId !== null) {
            $transaction->statement_id = $this->chosen($account, $statementId)->id;

            return;
        }

        $statement = $transaction->isTransferLeg() && $transaction->direction === Direction::In
            ? $this->resolver->forPayment($account, $transaction->date)
            : $this->resolver->forDate($account, $transaction->date);

        $transaction->statement_id = $statement->id;
    }

    /**
     * @throws StatementAccountMismatch
     */
    public function chosen(Account $card, int $statementId): CardStatement
    {
        $statement = CardStatement::query()->findOrFail($statementId);

        if ($statement->account_id !== $card->id) {
            throw new StatementAccountMismatch;
        }

        return $statement;
    }
}
