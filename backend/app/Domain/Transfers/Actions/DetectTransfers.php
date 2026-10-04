<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Data\TransferPair;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Domain\Transfers\Queries\TransferCandidates;
use App\Domain\Transfers\Support\TransferMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * Candidatas do banco (App\Domain\Transfers\Queries\TransferCandidates) →
 * App\Domain\Transfers\Support\TransferMatcher (puro) → liga cada match
 * mútuo com LinkTransfer e grava uma sugestão pendente para cada par acima
 * do limiar que não foi ligado de verdade. Chamada ao fim de
 * IngestTransactions (só para as transações inseridas pelo lote) e sob
 * demanda (POST /transfer-suggestions/detect).
 */
final class DetectTransfers
{
    private const DEFAULT_DAYS = 90;

    public function __construct(private readonly LinkTransfer $linkTransfer) {}

    /**
     * @param  list<int>|null  $transactionIds  null: candidatas dos últimos $days dias. Dado: essas transações + candidatas em ±2 dias das datas delas, e só pares com pelo menos um lado entre os ids.
     * @return array{linked: int, suggested: int, undo: list<array{transaction_id: int, attributes: array<string, mixed>}>}
     */
    public function handle(?array $transactionIds = null, int $days = self::DEFAULT_DAYS): array
    {
        $transactions = TransferCandidates::query($transactionIds, $days)->get();

        if ($transactions->count() < 2) {
            return ['linked' => 0, 'suggested' => 0, 'undo' => []];
        }

        $candidates = TransferCandidates::toCandidates($transactions);
        $dismissed = TransferCandidates::dismissedPairs($transactions->pluck('id')->all());

        $result = TransferMatcher::match($candidates, $dismissed, TransferCandidates::MAX_DAYS);

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
                $this->linkTransfer->handle($out, $in, TransferCandidates::MAX_DAYS);
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
