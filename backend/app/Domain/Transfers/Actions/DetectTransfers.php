<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Data\TransferCandidate;
use App\Domain\Transfers\Data\TransferPair;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Domain\Transfers\Support\TransferMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * Candidatas do banco → App\Domain\Transfers\Support\TransferMatcher (puro)
 * → liga cada match mútuo com LinkTransfer e grava uma sugestão pendente
 * para cada par acima do limiar que não foi ligado. Chamada ao fim de
 * IngestTransactions (só para as transações inseridas pelo lote) e sob
 * demanda (POST /transfer-suggestions/detect).
 */
final class DetectTransfers
{
    private const MAX_DAYS = 2;

    private const DEFAULT_DAYS = 90;

    public function __construct(private readonly LinkTransfer $linkTransfer) {}

    /**
     * @param  list<int>|null  $transactionIds  null: candidatas dos últimos $days dias. Dado: essas transações + candidatas em ±2 dias das datas delas, e só pares com pelo menos um lado entre os ids.
     * @return array{linked: int, suggested: int}
     */
    public function handle(?array $transactionIds = null, int $days = self::DEFAULT_DAYS): array
    {
        $transactions = $this->candidatesQuery($transactionIds, $days)->get();

        if ($transactions->count() < 2) {
            return ['linked' => 0, 'suggested' => 0];
        }

        $candidates = $this->toCandidates($transactions);
        $dismissed = $this->dismissedPairs($transactions->pluck('id')->all());

        $result = TransferMatcher::match($candidates, $dismissed, self::MAX_DAYS);

        $links = $result['links'];
        $suggestions = $result['suggestions'];

        if ($transactionIds !== null) {
            $idSet = array_fill_keys($transactionIds, true);
            $involvesId = fn (TransferPair $pair) => isset($idSet[$pair->outId]) || isset($idSet[$pair->inId]);

            $links = array_values(array_filter($links, $involvesId));
            $suggestions = array_values(array_filter($suggestions, $involvesId));
        }

        return [
            'linked' => $this->applyLinks($links, $transactions->keyBy('id')),
            'suggested' => $this->storeSuggestions($suggestions),
        ];
    }

    /**
     * @param  list<int>|null  $transactionIds
     * @return Builder<Transaction>
     */
    private function candidatesQuery(?array $transactionIds, int $days): Builder
    {
        $base = $this->baseCandidateQuery();

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
            ->whereIn('id', $transactionIds)
            ->selectRaw('min(date) as min_date, max(date) as max_date')
            ->first();

        if ($range->min_date === null) {
            return $base->whereIn('id', $transactionIds);
        }

        $from = CarbonImmutable::parse($range->min_date)->subDays(self::MAX_DAYS)->toDateString();
        $to = CarbonImmutable::parse($range->max_date)->addDays(self::MAX_DAYS)->toDateString();

        return $base->where(fn (Builder $query) => $query
            ->whereIn('id', $transactionIds)
            ->orWhereBetween('date', [$from, $to]));
    }

    /**
     * @return Builder<Transaction>
     */
    private function baseCandidateQuery(): Builder
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
    private function toCandidates(Collection $transactions): array
    {
        $accountsById = Account::query()
            ->whereIn('id', $transactions->pluck('account_id')->unique())
            ->get()
            ->keyBy('id');

        return $transactions->map(function (Transaction $transaction) use ($accountsById) {
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
            );
        })->values()->all();
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array<string, true>
     */
    private function dismissedPairs(array $candidateIds): array
    {
        $rows = TransferSuggestion::query()
            ->where('status', TransferSuggestionStatus::Dismissed)
            ->where(fn (Builder $query) => $query
                ->whereIn('out_transaction_id', $candidateIds)
                ->orWhereIn('in_transaction_id', $candidateIds))
            ->get(['out_transaction_id', 'in_transaction_id']);

        $dismissed = [];
        foreach ($rows as $row) {
            $dismissed["{$row->out_transaction_id}:{$row->in_transaction_id}"] = true;
        }

        return $dismissed;
    }

    /**
     * Pula (sem contar) um par que falhou por concorrência: outra chamada
     * já ligou ou alterou uma das pernas entre a consulta das candidatas e
     * agora.
     *
     * @param  list<TransferPair>  $links
     * @param  Collection<int, Transaction>  $transactionsById
     */
    private function applyLinks(array $links, Collection $transactionsById): int
    {
        $linked = 0;

        foreach ($links as $pair) {
            $out = $transactionsById->get($pair->outId);
            $in = $transactionsById->get($pair->inId);

            if ($out === null || $in === null) {
                continue;
            }

            try {
                $this->linkTransfer->handle($out, $in, self::MAX_DAYS);
                $linked++;
            } catch (ModelNotFoundException|TransferLinkInvalid) {
                // Concorrência: uma das duas pernas já não está mais disponível como era.
            }
        }

        return $linked;
    }

    /**
     * insertOrIgnore (não create um a um): idempotente pela unique
     * (out_transaction_id, in_transaction_id) sem uma consulta de
     * existência por par, e devolve só a contagem do que de fato inseriu.
     *
     * @param  list<TransferPair>  $suggestions
     */
    private function storeSuggestions(array $suggestions): int
    {
        if ($suggestions === []) {
            return 0;
        }

        $userId = Auth::id();
        $now = CarbonImmutable::now();

        $rows = array_map(fn (TransferPair $pair) => [
            'user_id' => $userId,
            'out_transaction_id' => $pair->outId,
            'in_transaction_id' => $pair->inId,
            'score' => $pair->score,
            'status' => TransferSuggestionStatus::Pending->value,
            'created_at' => $now,
            'updated_at' => $now,
        ], $suggestions);

        return TransferSuggestion::query()->insertOrIgnore($rows);
    }
}
