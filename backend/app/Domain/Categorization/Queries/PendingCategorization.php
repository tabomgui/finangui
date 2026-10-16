<?php

namespace App\Domain\Categorization\Queries;

use App\Domain\Categorization\Data\PendingCategorizationCount;
use App\Domain\Categorization\Data\PendingCategorizationResult;
use App\Domain\Categorization\Data\PendingGroup;
use App\Domain\Categorization\Data\PendingTransactionItem;
use App\Domain\Categorization\Support\CounterpartyKey;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Lançamentos pendentes de categoria (Transaction::scopePendingCategorization()),
 * agrupados por App\Domain\Categorization\Support\CounterpartyKey. O
 * conjunto pendente é pequeno o bastante para agrupar em PHP; acima de
 * $limit, só os mais recentes entram e o resultado vem com truncated=true.
 */
final class PendingCategorization
{
    public const DEFAULT_LIMIT = 5000;

    public function __construct(private readonly HistoryCategorizer $history) {}

    public function pending(?Direction $direction = null, ?string $search = null, int $limit = self::DEFAULT_LIMIT): PendingCategorizationResult
    {
        $query = Transaction::query()
            ->pendingCategorization()
            ->with('account')
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($direction !== null) {
            $query->where('direction', $direction->value);
        }

        /** @var Collection<int, Transaction> $transactions */
        $transactions = $query->limit($limit + 1)->get();

        $truncated = $transactions->count() > $limit;

        if ($truncated) {
            $transactions = $transactions->take($limit);
        }

        $groups = $this->buildGroups($transactions);

        if ($search !== null && trim($search) !== '') {
            $groups = $groups->filter(fn (PendingGroup $group) => $this->matchesSearch($group, $search))->values();
        }

        return new PendingCategorizationResult(
            totalPending: (int) $groups->sum(fn (PendingGroup $group) => count($group->transactions)),
            groupCount: $groups->count(),
            truncated: $truncated,
            groups: $groups->all(),
        );
    }

    /**
     * Só os totais (para o aviso de pendentes na Início): lê só
     * description_key dos pendentes, sem carregar os lançamentos inteiros
     * nem o histórico — o agrupamento por contraparte é o mesmo de
     * pending(), calculado em PHP sobre as chaves.
     */
    public function count(): PendingCategorizationCount
    {
        /** @var Collection<int, string> $descriptionKeys */
        $descriptionKeys = Transaction::query()->pendingCategorization()->pluck('description_key');

        $groupKeys = $descriptionKeys->map(CounterpartyKey::fromDescriptionKey(...))->unique();

        return new PendingCategorizationCount(
            totalPending: $descriptionKeys->count(),
            groupCount: $groupKeys->count(),
        );
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, PendingGroup>
     */
    private function buildGroups(Collection $transactions): Collection
    {
        /** @var Collection<string, Collection<int, Transaction>> $byKey */
        $byKey = $transactions->groupBy(fn (Transaction $t) => CounterpartyKey::fromDescriptionKey($t->description_key));

        $historyMemo = $this->historyMemo($transactions);

        return $byKey
            ->map(fn (Collection $group, string $key) => $this->buildGroup($key, $group, $historyMemo))
            ->values()
            ->sortByDesc(fn (PendingGroup $group) => count($group->transactions))
            ->values();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, int>
     */
    private function historyMemo(Collection $transactions): array
    {
        $pairs = $transactions
            ->map(fn (Transaction $t) => ['description_key' => $t->description_key, 'direction' => $t->direction->value])
            ->values()
            ->all();

        return $this->history->suggestMany($pairs);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $historyMemo
     */
    private function buildGroup(string $key, Collection $transactions, array $historyMemo): PendingGroup
    {
        $outCount = $transactions->filter(fn (Transaction $t) => $t->direction === Direction::Out)->count();
        $inCount = $transactions->count() - $outCount;

        $net = $transactions->sum(fn (Transaction $t) => $t->direction === Direction::In ? $t->amount->cents : -$t->amount->cents);

        $mostRecent = $transactions->sortByDesc(fn (Transaction $t) => [$t->date->toDateString(), $t->id])->first();

        return new PendingGroup(
            key: $key,
            name: CounterpartyKey::displayName($key, $mostRecent?->description),
            outCount: $outCount,
            inCount: $inCount,
            net: (int) $net,
            suggestionCategoryId: $this->suggestion($transactions, $historyMemo),
            transactions: $transactions
                ->sortByDesc(fn (Transaction $t) => [$t->date->toDateString(), $t->id])
                ->map($this->toItem(...))
                ->values()
                ->all(),
        );
    }

    /**
     * Sugestão pelo histórico para o grupo: só quando todos os lançamentos
     * são da mesma direção e, entre as description_key distintas do grupo
     * nessa direção, o histórico aponta uma única categoria em comum — um
     * grupo pode reunir mais de uma description_key (ex.: mesma contraparte
     * com e sem sufixo de cidade), e sugestões divergentes entre elas não
     * dão uma resposta confiável.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $historyMemo
     */
    private function suggestion(Collection $transactions, array $historyMemo): ?int
    {
        $directions = $transactions->map(fn (Transaction $t) => $t->direction)->unique();

        if ($directions->count() !== 1) {
            return null;
        }

        /** @var Direction $direction */
        $direction = $directions->first();

        $categoryIds = $transactions
            ->map(fn (Transaction $t) => $t->description_key)
            ->unique()
            ->map(fn (string $descriptionKey) => $historyMemo[CategorizeTransaction::historyKey($descriptionKey, $direction)] ?? null)
            ->filter()
            ->unique();

        return $categoryIds->count() === 1 ? $categoryIds->first() : null;
    }

    private function matchesSearch(PendingGroup $group, string $search): bool
    {
        $needle = TextNormalizer::normalize($search);

        if ($needle === '') {
            return true;
        }

        if (str_contains(TextNormalizer::normalize($group->key), $needle)) {
            return true;
        }

        foreach ($group->transactions as $item) {
            if (str_contains(TextNormalizer::normalize($item->description), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function toItem(Transaction $transaction): PendingTransactionItem
    {
        return new PendingTransactionItem(
            id: $transaction->id,
            date: $transaction->date->toDateString(),
            description: $transaction->description,
            amount: $transaction->amount->cents,
            direction: $transaction->direction->value,
            accountId: $transaction->account_id,
            accountName: $transaction->account->name,
        );
    }
}
