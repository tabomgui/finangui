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
 * escolhida, se informada; para a entrada de um pagamento, a última fatura
 * fechada; para o resto, a fatura da data.
 *
 * Uma entrada é tratada como pagamento (card_payment_statement_id igual a
 * statement_id — única marca que App\Domain\Cards\Models\CardStatement::scopeWithTotals
 * usa para separar pagamento de estorno) quando já é perna de transferência
 * OU quando já chegou marcada como pagamento por outra via — ver
 * App\Domain\Banking\Actions\ReconcileCardPayments, que reconhece créditos
 * do banco sem transferência ligada (pela fatura do banco ou pelo padrão de
 * descrição). Isso preserva a marca através de uma edição comum (ex.: só a
 * data) feita por App\Domain\Transactions\Actions\UpdateTransaction ou por
 * uma reimportação (App\Domain\Imports\Actions\MatchedTransactionOutcomes).
 *
 * Quando a transação já estava marcada como pagamento reconhecido
 * (card_payment_statement_id não nulo — ver App\Domain\Banking\Actions\ReconcileCardPayments)
 * ANTES desta chamada, E a fatura atual ainda pertence a $account, a fatura
 * atual nunca é recalculada aqui: ela foi escolhida de propósito (pela
 * fatura do banco ou pela própria resolução de pagamento) e precisa
 * sobreviver a uma nova passagem por este método —
 * ex.: App\Domain\Transfers\Actions\LinkTransfer chama isto de novo logo
 * depois de transformar um crédito já reconciliado numa perna de
 * transferência. Isso nunca vale para um crédito comum que só agora está
 * virando perna de transferência (ex.: App\Domain\Transfers\Actions\DetectTransfers,
 * uma sugestão aceita, ou "juntar à mão"): mesmo já tendo um statement_id
 * (todo lançamento ganha um na criação), ele nunca foi reconhecido como
 * pagamento antes — a fatura é recalculada por StatementResolver::forPayment(),
 * exatamente como no restante do fluxo de pagamento. Isso também nunca vale
 * para a saída de uma transferência a partir do cartão (ex.: um saque): a
 * saída não é pagamento, então sempre recalcula pela data normal.
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
            $transaction->card_payment_statement_id = null;

            return;
        }

        $isPayment = $transaction->direction === Direction::In
            && ($transaction->isTransferLeg() || $transaction->isCardPayment());

        $statement = match (true) {
            $statementId !== null => $this->chosen($account, $statementId),
            // $transaction->isCardPayment() (não isTransferLeg()): todo
            // lançamento já tem um statement_id desde a criação, então
            // "é perna de transferência e tem statement_id" seria quase
            // sempre verdade — a condição certa é "já era um pagamento
            // reconhecido antes desta chamada". Só mantém quando a fatura
            // atual é mesmo do $account: se account_id mudou (ex.: movida
            // para outro cartão), a fatura antiga é de outro cartão e
            // precisa ser recalculada normalmente.
            $this->keepsCurrentStatement($transaction, $account) => CardStatement::query()->findOrFail($transaction->statement_id),
            $isPayment => $this->resolver->forPayment($account, $transaction->date),
            default => $this->resolver->forDate($account, $transaction->date),
        };

        $transaction->statement_id = $statement->id;
        $transaction->card_payment_statement_id = $isPayment ? $statement->id : null;
    }

    private function keepsCurrentStatement(Transaction $transaction, Account $account): bool
    {
        if (! $transaction->isCardPayment() || $transaction->statement_id === null) {
            return false;
        }

        return CardStatement::query()->whereKey($transaction->statement_id)->where('account_id', $account->id)->exists();
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
