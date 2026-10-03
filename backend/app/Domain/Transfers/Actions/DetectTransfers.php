<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
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
 * para cada par acima do limiar que não foi ligado de verdade. Chamada ao
 * fim de IngestTransactions (só para as transações inseridas pelo lote) e
 * sob demanda (POST /transfer-suggestions/detect).
 */
final class DetectTransfers
{
    private const MAX_DAYS = 2;

    private const DEFAULT_DAYS = 90;

    public function __construct(private readonly LinkTransfer $linkTransfer) {}

    /**
     * @param  list<int>|null  $transactionIds  null: candidatas dos últimos $days dias. Dado: essas transações + candidatas em ±2 dias das datas delas, e só pares com pelo menos um lado entre os ids.
     * @return array{linked: int, suggested: int, undo: list<array{transaction_id: int, attributes: array<string, mixed>}>}
     */
    public function handle(?array $transactionIds = null, int $days = self::DEFAULT_DAYS): array
    {
        $transactions = $this->candidatesQuery($transactionIds, $days)->get();

        if ($transactions->count() < 2) {
            return ['linked' => 0, 'suggested' => 0, 'undo' => []];
        }

        $candidates = $this->toCandidates($transactions);
        $dismissed = $this->dismissedPairs($transactions->pluck('id')->all());

        $result = TransferMatcher::match($candidates, $dismissed, self::MAX_DAYS);

        $links = $result['links'];
        $suggestions = $result['suggestions'];

        /** @var array<int, true> $idSet ids do próprio lote; vazio quando a chamada não tem lote (detecção sob demanda) */
        $idSet = $transactionIds !== null ? array_fill_keys($transactionIds, true) : [];

        if ($transactionIds !== null) {
            $involvesId = fn (TransferPair $pair) => isset($idSet[$pair->outId]) || isset($idSet[$pair->inId]);

            $links = array_values(array_filter($links, $involvesId));
            $suggestions = array_values(array_filter($suggestions, $involvesId));
        }

        [$linked, $linkedIds, $undo] = $this->applyLinks($links, $transactions->keyBy('id'), $idSet);

        // Só exclui a sugestão de um par cujo id realmente ligou no banco
        // (não todo id que o TransferMatcher propôs como link): uma
        // tentativa que falhou por concorrência (ver applyLinks()) não
        // pode apagar a segunda melhor opção de ninguém.
        $remainingSuggestions = array_values(array_filter(
            $suggestions,
            fn (TransferPair $pair) => ! isset($linkedIds[$pair->outId]) && ! isset($linkedIds[$pair->inId]),
        ));

        return [
            'linked' => $linked,
            'suggested' => $this->storeSuggestions($remainingSuggestions),
            'undo' => $undo,
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
            ->whereIntegerInRaw('id', $transactions->pluck('account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $manualNonTransferFlags = $this->manualNonTransferCategoryFlags($transactions);

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
    private function manualNonTransferCategoryFlags(Collection $transactions): array
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
    private function dismissedPairs(array $candidateIds): array
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

    /**
     * Tenta ligar cada match mútuo de verdade (LinkTransfer), pulando sem
     * contar o que falhar por concorrência (uma das pernas já não está
     * mais disponível como era entre a consulta das candidatas e agora).
     * Para a perna que não pertence ao próprio lote ($idSet, ver
     * externalLeg()), grava em `undo` a categoria/fatura de antes de
     * ligar: IngestTransactions mescla isso ao undo do lote, para
     * RevertImportBatch devolver essa perna à categoria que tinha antes da
     * detecção (em vez de ficar sem categoria, ou com uma nova sugerida só
     * pelo acaso da ligação ter sido desfeita).
     *
     * @param  list<TransferPair>  $links
     * @param  Collection<int, Transaction>  $transactionsById
     * @param  array<int, true>  $idSet
     * @return array{0: int, 1: array<int, true>, 2: list<array{transaction_id: int, attributes: array<string, mixed>}>}
     */
    private function applyLinks(array $links, Collection $transactionsById, array $idSet): array
    {
        $linked = 0;
        /** @var array<int, true> $linkedIds */
        $linkedIds = [];
        $undo = [];

        foreach ($links as $pair) {
            $out = $transactionsById->get($pair->outId);
            $in = $transactionsById->get($pair->inId);

            if ($out === null || $in === null) {
                continue;
            }

            $externalLeg = $this->externalLeg($out, $in, $idSet);

            try {
                $this->linkTransfer->handle($out, $in, self::MAX_DAYS);
            } catch (ModelNotFoundException|TransferLinkInvalid) {
                continue;
            }

            $linked++;
            $linkedIds[$pair->outId] = true;
            $linkedIds[$pair->inId] = true;

            if ($externalLeg !== null) {
                $undo[] = [
                    'transaction_id' => $externalLeg->id,
                    'attributes' => [
                        'category_id' => $externalLeg->category_id,
                        'categorized_by' => $externalLeg->categorized_by,
                        'statement_id' => $externalLeg->statement_id,
                    ],
                ];
            }
        }

        return [$linked, $linkedIds, $undo];
    }

    /**
     * A perna que não pertence ao lote que chamou handle() ($idSet): uma
     * saída e uma entrada de transferência nunca são da mesma conta (ver
     * TransferMatcher::isPossiblePair()), e um lote de importação é sempre
     * de uma única conta — então, quando $idSet não é vazio, no máximo uma
     * das duas pernas pode estar fora dele. Sem lote ($idSet vazio,
     * detecção sob demanda), não há undo de ninguém para gravar.
     *
     * @param  array<int, true>  $idSet
     */
    private function externalLeg(Transaction $out, Transaction $in, array $idSet): ?Transaction
    {
        if ($idSet === []) {
            return null;
        }

        if (! isset($idSet[$out->id])) {
            return $out;
        }

        if (! isset($idSet[$in->id])) {
            return $in;
        }

        return null;
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
