<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Actions\CreateInstallmentPurchase;
use App\Domain\Recurrences\Support\RecurrenceMatcher;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Transactions\Data\TransactionData;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Cria um lançamento manual — exceto quando, sem parcelas, ele casa com
 * uma ocorrência prevista de recorrência ainda não confirmada (mesma
 * conta e direção, dentro da tolerância/janela/descrição de
 * RecurrenceMatcher): nesse caso confirma a prevista com os dados do
 * formulário (vira posted; mantém recurrence_id/recurrence_date) em vez
 * de criar outra transação, para o frontend avisar o usuário.
 */
final class CreateTransaction
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly CreateInstallmentPurchase $createInstallmentPurchase,
        private readonly CategorizeTransaction $categorize,
        private readonly RecurrenceMatcher $recurrenceMatcher,
    ) {}

    public function handle(TransactionData $data): Transaction
    {
        if ($data->installments > 1) {
            return $this->createInstallmentPurchase->handle($data);
        }

        return DB::transaction(function () use ($data) {
            $account = Account::query()->findOrFail($data->accountId);

            // Ignorada nunca confirma uma prevista: ela não entra em saldo
            // nem relatório, então não faz sentido "gastar" a ocorrência
            // prevista com ela — a prevista continua livre para casar com o
            // lançamento real de verdade, quando ele chegar.
            $occurrence = $data->isIgnored ? null : $this->matchRecurrence($account, $data);

            if ($occurrence !== null) {
                return $this->confirmOccurrence($occurrence, $account, $data);
            }

            $transaction = new Transaction([
                'account_id' => $account->id,
                'date' => $data->date,
                'amount' => $data->amount,
                'direction' => $data->direction,
                'currency' => $account->currency,
                'description' => $data->description,
                'original_description' => $data->description,
                'notes' => $data->notes,
                'payee' => $data->payee,
                'category_id' => $data->categoryId,
                'categorized_by' => $data->categoryId !== null ? 'manual' : null,
                'status' => TransactionStatus::Posted,
                'source' => TransactionSource::Manual,
                'is_ignored' => $data->isIgnored,
            ]);

            if ($data->categoryId === null) {
                $this->categorize->handle($transaction);
            }

            $this->assignStatement->handle($transaction, $data->statementId);
            $transaction->save();

            $transaction->tags()->sync($data->tagIds);

            return $transaction->load(['account', 'category.parent', 'tags', 'installmentPlan']);
        });
    }

    /**
     * Previstas da mesma conta, sem external_id (uma vez linkada por
     * qualquer caminho, ela deixa de ser uma prevista "livre" — ver
     * IngestionPlanner::isProjectedRecurrenceOccurrence()) e com
     * recurrence_date numa janela que cobre a de RecurrenceMatcher (−5/+3
     * dias em torno da data real, "como a adoção"): só um pré-filtro em SQL
     * para não carregar previstas antigas sem chance de casar — o
     * RecurrenceMatcher é quem decide de fato. lockForUpdate(), dentro da
     * mesma transação de handle(), evita que duas confirmações concorrentes
     * (ex.: duplo clique) casem com a mesma prevista duas vezes. Carregada
     * com a recorrência (match_pattern, ver RecurrenceMatcher::descriptionMatches()).
     */
    private function matchRecurrence(Account $account, TransactionData $data): ?Transaction
    {
        $windowStart = $data->date->subDays(5)->toDateString();
        $windowEnd = $data->date->addDays(3)->toDateString();

        $pool = Transaction::query()
            ->where('account_id', $account->id)
            ->unconfirmedOccurrences()
            ->whereBetween('recurrence_date', [$windowStart, $windowEnd])
            ->with('recurrence')
            ->lockForUpdate()
            ->get();

        if ($pool->isEmpty()) {
            return null;
        }

        return $this->recurrenceMatcher->bestMatch(
            $pool, [], $data->amount->cents, $data->direction, $data->date->toDateString(), $data->description,
        );
    }

    /**
     * Vira posted com os dados do formulário; mantém recurrence_id e
     * recurrence_date como estavam. Categoria só muda quando o formulário
     * informa uma — sem ela, mantém a que a prevista já tinha (vinda do
     * modelo da recorrência); se nem o formulário nem a prevista têm
     * categoria, tenta classificar automaticamente (regras → histórico),
     * do mesmo jeito que um lançamento novo sem categoria.
     */
    private function confirmOccurrence(Transaction $occurrence, Account $account, TransactionData $data): Transaction
    {
        $occurrence->date = $data->date;
        $occurrence->amount = $data->amount;
        $occurrence->currency = $account->currency;
        $occurrence->description = $data->description;
        $occurrence->original_description = $data->description;
        $occurrence->notes = $data->notes;
        $occurrence->payee = $data->payee;
        $occurrence->is_ignored = $data->isIgnored;
        $occurrence->status = TransactionStatus::Posted;

        if ($data->categoryId !== null) {
            $occurrence->category_id = $data->categoryId;
            $occurrence->categorized_by = 'manual';
        } elseif ($occurrence->category_id === null) {
            $this->categorize->handle($occurrence);
        }

        $this->assignStatement->handle($occurrence, $data->statementId);
        $occurrence->save();

        $occurrence->tags()->sync($data->tagIds);

        return $occurrence->load(['account', 'category.parent', 'tags', 'installmentPlan', 'recurrence']);
    }
}
