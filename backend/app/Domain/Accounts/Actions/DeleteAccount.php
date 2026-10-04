<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Errors\AccountHasTransactions;
use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Actions\DeleteRecurrence;
use App\Domain\Recurrences\Models\Recurrence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Uma ocorrência de recorrência ainda não confirmada
 * (Transaction::isUnconfirmedOccurrence()) não é um lançamento de verdade:
 * não impede a exclusão, e some junto com a conta. Qualquer outra
 * transação (lançada, ou uma prevista já adotada por importação/banco)
 * ainda impede — arquive em vez de excluir.
 */
final class DeleteAccount
{
    public function __construct(private readonly DeleteRecurrence $deleteRecurrence) {}

    /**
     * @throws AccountHasTransactions
     */
    public function handle(Account $account): void
    {
        DB::transaction(function () use ($account) {
            $hasRealTransactions = $account->transactions()
                ->whereNot(fn (Builder $query) => $query->unconfirmedOccurrences())
                ->exists();

            if ($hasRealTransactions) {
                throw new AccountHasTransactions;
            }

            // Pelo recurrence_id, não pela conta: uma recorrência atual desta
            // conta pode ter deixado uma ocorrência não confirmada antiga
            // (data passada) numa conta anterior, antes de ser editada para
            // esta — DeleteRecurrence já lida com isso, não importa onde a
            // ocorrência esteja.
            Recurrence::query()->where('account_id', $account->id)->get()
                ->each(fn (Recurrence $recurrence) => $this->deleteRecurrence->handle($recurrence));

            $account->transactions()->unconfirmedOccurrences()->delete();

            $account->delete();
        });
    }
}
