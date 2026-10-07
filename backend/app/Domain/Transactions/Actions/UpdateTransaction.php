<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Support\CardPaymentDescriptionPatterns;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Errors\InstallmentLocked;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Errors\TransactionCurrencyMismatch;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Errors\TransferLegLocked;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateTransaction
{
    /** Campos que, numa perna de transferência, só mudam pelo endpoint de transferência. */
    private const TRANSFER_LOCKED = ['account_id', 'date', 'amount', 'direction', 'is_ignored'];

    /** Campos que, numa parcela, seguem o parcelamento. */
    private const INSTALLMENT_LOCKED = ['account_id', 'date', 'amount', 'direction'];

    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly StatementResolver $statementResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     *
     * @throws TransferLegLocked
     * @throws InstallmentLocked
     * @throws TransactionCurrencyMismatch
     */
    public function handle(Transaction $transaction, array $input): Transaction
    {
        $input = $this->normalizeTypes($input);

        if ($transaction->isTransferLeg() && $this->lockedFieldsChanged($transaction, $input, self::TRANSFER_LOCKED)) {
            throw new TransferLegLocked;
        }

        if ($transaction->isInstallment() && $this->lockedFieldsChanged($transaction, $input, self::INSTALLMENT_LOCKED)) {
            throw new InstallmentLocked;
        }

        return DB::transaction(function () use ($transaction, $input) {
            $tagIds = Arr::pull($input, 'tag_ids');
            $statementId = Arr::pull($input, 'statement_id');

            if (array_key_exists('account_id', $input) && $input['account_id'] !== $transaction->account_id) {
                $destination = Account::query()->findOrFail($input['account_id']);

                if ($destination->currency !== $transaction->currency) {
                    throw new TransactionCurrencyMismatch;
                }
            }

            $transaction->fill($input);

            if ($transaction->isDirty('description')) {
                $transaction->description_locked = true;
            }
            if ($transaction->isDirty('category_id')) {
                $transaction->categorized_by = $transaction->category_id !== null ? 'manual' : null;
            }
            // card_payment_locked só é relevante para App\Domain\Banking\Actions\ReconcileCardPayments,
            // que só processa entradas (direction in) de contas de cartão — gravar a trava numa
            // saída, ou numa conta comum, nunca seria lido por ela e só poluiria o dado.
            if (array_key_exists('is_ignored', $input) && $transaction->isDirty('is_ignored') && $transaction->direction === Direction::In) {
                $account = Account::query()->find($transaction->account_id);

                if ($account !== null && $account->isCreditCard()) {
                    $wasAutoIgnored = $transaction->ignored_reason !== null;

                    // O usuário decidiu isso de propósito: o motivo automático
                    // (ex.: pagamento duplicado) não se aplica mais, e
                    // App\Domain\Banking\Actions\ReconcileCardPayments nunca
                    // mais reconsidera este lançamento — ver card_payment_locked.
                    $transaction->ignored_reason = null;
                    $transaction->card_payment_locked = true;

                    // Deixou de ignorar algo que tinha sido ignorado
                    // automaticamente (ex.: duplicata) — se a descrição ainda
                    // bate com um pagamento de fatura, reconhece na hora: a
                    // reconciliação nunca mais vai tocar nisso de novo.
                    if ($wasAutoIgnored && ! $transaction->is_ignored) {
                        $this->remarkAsPaymentIfStillMatches($transaction, $account);
                    }
                }
            }

            if ($statementId !== null) {
                $this->assignStatement->handle($transaction, (int) $statementId);
            } elseif ($transaction->isDirty(['account_id', 'date', 'direction'])) {
                // Pagamento reconhecido virando saída (ex.: estava marcado
                // como pagamento por engano): deixa de ser pagamento antes
                // de recalcular, senão AssignStatement manteria a fatura
                // antiga por ainda ver card_payment_statement_id preenchido.
                if ($transaction->isDirty('direction') && $transaction->direction === Direction::Out && $transaction->isCardPayment()) {
                    $transaction->card_payment_statement_id = null;
                }

                $this->assignStatement->handle($transaction);
            }

            $transaction->save();

            if ($tagIds !== null) {
                $transaction->tags()->sync($tagIds);
            }

            return $transaction->load(['account', 'category.parent', 'tags', 'installmentPlan', 'recurrence']);
        });
    }

    /**
     * Reconhece na hora um crédito de cartão que o usuário acabou de deixar
     * de ignorar: sem isso, ele ficaria "comum" (fatura pela data, contando
     * como estorno) até a próxima reconciliação — que nunca mais vai rodar
     * sobre ele, porque card_payment_locked já foi marcado acima. Só a
     * descrição decide aqui (sem dado de fatura do banco disponível neste
     * fluxo) — mesmo critério de App\Domain\Banking\Support\CardPaymentDescriptionPatterns
     * usado por App\Domain\Banking\Actions\ReconcileCardPayments quando não
     * há `payments[]`. Sem bater o padrão, ou sem dias de fechamento/vencimento
     * ainda cadastrados, fica como um lançamento comum — a decisão do
     * usuário já foi respeitada. $account já é um cartão (ver chamador).
     */
    private function remarkAsPaymentIfStillMatches(Transaction $transaction, Account $account): void
    {
        if (! CardPaymentDescriptionPatterns::matches($transaction->description)) {
            return;
        }

        if ($account->closing_day === null || $account->due_day === null) {
            return;
        }

        $statement = $this->statementResolver->forPayment($account, $transaction->date);

        $transaction->statement_id = $statement->id;
        $transaction->card_payment_statement_id = $statement->id;
    }

    /**
     * Garante inteiros reais: "4590" e 4590.0 passam a regra de validação `integer`,
     * mas o MoneyCast só aceita Money ou int de verdade.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeTypes(array $input): array
    {
        if (array_key_exists('amount', $input)) {
            $input['amount'] = (int) $input['amount'];
        }
        if (array_key_exists('account_id', $input)) {
            $input['account_id'] = (int) $input['account_id'];
        }
        if (array_key_exists('category_id', $input) && $input['category_id'] !== null) {
            $input['category_id'] = (int) $input['category_id'];
        }
        if (array_key_exists('is_ignored', $input)) {
            $input['is_ignored'] = filter_var($input['is_ignored'], FILTER_VALIDATE_BOOLEAN);
        }

        return $input;
    }

    /**
     * O frontend pode reenviar o objeto inteiro no PATCH; só trava quando um
     * campo bloqueado realmente muda de valor, não apenas por estar presente.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $fields
     */
    private function lockedFieldsChanged(Transaction $transaction, array $input, array $fields): bool
    {
        $current = [
            'account_id' => $transaction->account_id,
            'date' => $transaction->date->toDateString(),
            'amount' => $transaction->amount->cents,
            'direction' => $transaction->direction->value,
            'is_ignored' => $transaction->is_ignored,
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $current[$field]) {
                return true;
            }
        }

        return false;
    }
}
