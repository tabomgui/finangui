<?php

namespace App\Domain\Transfers\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Data\TransferCandidate;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Carrega as candidatas a transferência do banco para App\Domain\Transfers\Actions\DetectTransfers:
 * a consulta das transações elegíveis, a conversão para TransferCandidate (puro, sem banco — ver
 * TransferMatcher) e os pares já descartados antes.
 */
final class TransferCandidates
{
    public const MAX_DAYS = 2;

    /**
     * @param  list<int>|null  $transactionIds  null: candidatas dos últimos $days dias. Dado: essas transações + candidatas em ±2 dias das datas delas.
     * @return Builder<Transaction>
     */
    public static function query(?array $transactionIds, int $days): Builder
    {
        $base = self::baseQuery();

        if ($transactionIds === null) {
            return $base->where('date', '>=', CarbonImmutable::now()->subDays($days)->toDateString());
        }

        // Janela agregada (uma consulta, não uma por linha): min/max das
        // datas dos ids informados, sem filtrar pelas regras de candidata —
        // mesmo um id que não seja candidata (ex.: já ligado) ainda pode
        // ancorar a janela sem prejuízo, já que o matcher a ignora de
        // qualquer forma.
        // min()/max() sem GROUP BY sempre devolvem exatamente uma linha,
        // mesmo sem nenhum id encontrado (as colunas vêm nulas nesse caso,
        // nunca zero linhas) — first() aqui nunca é null.
        /** @var object{min_date: ?string, max_date: ?string} $range */
        $range = Transaction::query()
            ->whereIntegerInRaw('id', $transactionIds)
            ->selectRaw('min(date) as min_date, max(date) as max_date')
            ->first();

        if ($range->min_date === null) {
            return $base->whereIntegerInRaw('id', $transactionIds);
        }

        $from = CarbonImmutable::parse($range->min_date)->subDays(self::MAX_DAYS)->toDateString();
        $to = CarbonImmutable::parse($range->max_date)->addDays(self::MAX_DAYS)->toDateString();

        return $base->where(fn (Builder $query) => $query
            ->whereIntegerInRaw('id', $transactionIds)
            ->orWhereBetween('date', [$from, $to]));
    }

    /**
     * @return Builder<Transaction>
     */
    private static function baseQuery(): Builder
    {
        return Transaction::query()
            ->whereNull('transfer_id')
            ->where('is_ignored', false)
            ->whereIn('status', [TransactionStatus::Posted->value, TransactionStatus::Pending->value])
            ->whereNull('installment_plan_id')
            ->where('amount', '>', 0);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<TransferCandidate>
     */
    public static function toCandidates(Collection $transactions): array
    {
        $accountsById = Account::query()
            ->whereIntegerInRaw('id', $transactions->pluck('account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $manualNonTransferFlags = self::manualNonTransferCategoryFlags($transactions);

        return $transactions->map(function (Transaction $transaction) use ($accountsById, $manualNonTransferFlags) {
            /** @var Account $account */
            $account = $accountsById[$transaction->account_id];

            return new TransferCandidate(
                id: $transaction->id,
                accountId: $transaction->account_id,
                accountName: TextNormalizer::normalize($account->name),
                creditCard: $account->isCreditCard(),
                currency: $transaction->currency,
                direction: $transaction->direction,
                amount: $transaction->amount->cents,
                date: $transaction->date->toDateString(),
                description: TextNormalizer::normalize($transaction->description),
                pending: $transaction->status === TransactionStatus::Pending,
                manualNonTransferCategory: $manualNonTransferFlags[$transaction->id] ?? false,
            );
        })->values()->all();
    }

    /**
     * Uma consulta para todas as categorias manuais do lote inteiro (não
     * uma por transação): categoria (ou o pai dela) marcada como
     * transferência não impede a ligação automática — o usuário já teria
     * marcado a categoria como tal; qualquer outra categoria manual
     * impede (ver TransferMatcher::canAutoLink()). Categoria não
     * encontrada (ex.: excluída) conta como "não é de transferência" —
     * falha fechado, impede a ligação sozinha em vez de arriscar.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, bool> id da transação => tem categoria manual não-transferência
     */
    private static function manualNonTransferCategoryFlags(Collection $transactions): array
    {
        $categoryIds = $transactions
            ->filter(fn (Transaction $t) => $t->categorized_by === 'manual' && $t->category_id !== null)
            ->pluck('category_id')
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {
            return [];
        }

        $categories = Category::query()
            ->whereIntegerInRaw('id', $categoryIds->all())
            ->with('parent:id,is_transfer')
            ->get()
            ->keyBy('id');

        $flags = [];
        foreach ($transactions as $transaction) {
            if ($transaction->categorized_by !== 'manual' || $transaction->category_id === null) {
                continue;
            }

            $category = $categories->get($transaction->category_id);
            $isTransferCategory = $category !== null && $category->is_transfer;

            if (! $isTransferCategory && $category !== null) {
                // @phpstan-ignore nullsafe.neverNull (falso positivo: Larastan não enxerga que parent_id/parent são nullable; em runtime uma categoria raiz não tem parent)
                $isTransferCategory = $category->parent?->is_transfer ?? false;
            }

            $flags[$transaction->id] = ! $isTransferCategory;
        }

        return $flags;
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<string, true>
     */
    public static function dismissedPairs(array $candidateIds): array
    {
        $rows = TransferSuggestion::query()
            ->where('status', TransferSuggestionStatus::Dismissed)
            ->where(fn (Builder $query) => $query
                ->whereIntegerInRaw('out_transaction_id', $candidateIds)
                ->orWhereIntegerInRaw('in_transaction_id', $candidateIds))
            ->get(['out_transaction_id', 'in_transaction_id']);

        $dismissed = [];
        foreach ($rows as $row) {
            $dismissed["{$row->out_transaction_id}:{$row->in_transaction_id}"] = true;
        }

        return $dismissed;
    }
}
