<?php

namespace App\Domain\Categorization\Queries;

use App\Domain\Categories\Models\Category;
use App\Domain\Categorization\Data\PendingCategorizationCount;
use App\Domain\Categorization\Data\PendingCategorizationResult;
use App\Domain\Categorization\Data\PendingGroup;
use App\Domain\Categorization\Data\PendingTransactionItem;
use App\Domain\Categorization\Support\CategoryDirection;
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

    /**
     * Colunas de fato usadas por pending(): nunca carrega `raw` (jsonb bruto
     * do provedor, pode ser grande) nem o resto do model.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'account_id', 'date', 'amount', 'direction', 'description', 'original_description', 'description_key'];

    public function __construct(private readonly HistoryCategorizer $history) {}

    public function pending(?Direction $direction = null, ?string $search = null, int $limit = self::DEFAULT_LIMIT): PendingCategorizationResult
    {
        $query = Transaction::query()
            ->pendingCategorization()
            ->select(self::COLUMNS)
            ->with('account:id,name')
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

        // Truncado: a lista já é só um recorte, então total_pending soma dos
        // grupos devolvidos mentiria para baixo — usa a mesma contagem leve
        // de count() (o total pendente de verdade, sem o corte de $limit).
        $totalPending = $truncated
            ? $this->count()->totalPending
            : (int) $groups->sum(fn (PendingGroup $group) => count($group->transactions));

        return new PendingCategorizationResult(
            totalPending: $totalPending,
            groupCount: $groups->count(),
            truncated: $truncated,
            groups: $groups->all(),
        );
    }

    /**
     * Só os totais (para o aviso de pendentes na Início): lê só
     * description_key e description dos pendentes, sem carregar os
     * lançamentos inteiros nem o histórico — o agrupamento por contraparte
     * é o mesmo de pending(), calculado em PHP sobre essas duas colunas.
     */
    public function count(): PendingCategorizationCount
    {
        /** @var Collection<int, Transaction> $rows */
        $rows = Transaction::query()->pendingCategorization()->get(['description_key', 'description']);

        $groupKeys = $rows->map($this->groupingKey(...))->unique();

        return new PendingCategorizationCount(
            totalPending: $rows->count(),
            groupCount: $groupKeys->count(),
        );
    }

    /**
     * Chave de agrupamento de um lançamento: a description_key gravada,
     * salvo quando ela é vazia (descrição só de dígito/pontuação, ex.:
     * "12345" — TextNormalizer::key() não sobra nenhuma letra). Nesse caso
     * usa TextNormalizer::normalize() da própria descrição (mantém os
     * dígitos) como entrada de CounterpartyKey, senão todo lançamento sem
     * letra na descrição cairia na mesma chave vazia e juntaria
     * contrapartes sem nenhuma relação entre si.
     */
    private function groupingKey(Transaction $transaction): string
    {
        $descriptionKey = $transaction->description_key !== ''
            ? $transaction->description_key
            : TextNormalizer::normalize($transaction->description);

        return CounterpartyKey::fromDescriptionKey($descriptionKey);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, PendingGroup>
     */
    private function buildGroups(Collection $transactions): Collection
    {
        /** @var Collection<string, Collection<int, Transaction>> $byKey */
        $byKey = $transactions->groupBy($this->groupingKey(...));

        $historyMemo = $this->historyMemo($transactions);
        $suggestable = $this->suggestableCategories($historyMemo);

        return $byKey
            ->map(fn (Collection $group, string $key) => $this->buildGroup($key, $group, $historyMemo, $suggestable))
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
     * Categorias apontadas pelo histórico, numa única consulta (com o pai,
     * para CategoryDirection saber se a filha herda transferência).
     *
     * @param  array<string, int>  $historyMemo
     * @return Collection<int, Category>
     */
    private function suggestableCategories(array $historyMemo): Collection
    {
        if ($historyMemo === []) {
            return new Collection;
        }

        return Category::query()
            ->with('parent:id,is_transfer')
            ->whereKey(array_values(array_unique($historyMemo)))
            ->get(['id', 'parent_id', 'kind', 'is_transfer'])
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $historyMemo
     * @param  Collection<int, Category>  $suggestable
     */
    private function buildGroup(string $key, Collection $transactions, array $historyMemo, Collection $suggestable): PendingGroup
    {
        $outCount = $transactions->filter(fn (Transaction $t) => $t->direction === Direction::Out)->count();
        $inCount = $transactions->count() - $outCount;

        $net = $transactions->sum(fn (Transaction $t) => $t->direction === Direction::In ? $t->amount->cents : -$t->amount->cents);

        // Nunca nulo: um grupo vem de groupBy(), então $transactions tem
        // sempre pelo menos um lançamento.
        /** @var Transaction $mostRecent */
        $mostRecent = $transactions->sortByDesc(fn (Transaction $t) => [$t->date->toDateString(), $t->id])->first();

        return new PendingGroup(
            key: $key,
            // original_description (texto do banco), não description: é o
            // mesmo texto que a regra padrão do frontend casa com
            // `original_description contains <nome>`, e description pode
            // já ter sido editada/renomeada pelo usuário ou por uma regra.
            name: CounterpartyKey::displayName($key, $mostRecent->original_description),
            outCount: $outCount,
            inCount: $inCount,
            net: (int) $net,
            suggestionCategoryId: $this->suggestion($transactions, $historyMemo, $suggestable),
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
     * dão uma resposta confiável. Por ser calculada sobre $transactions já
     * filtrado por direção (ver pending()), um filtro ?direction= pode
     * fazer aparecer uma sugestão que não apareceria na listagem sem
     * filtro, porque o grupo inteiro (as duas direções juntas) é misto —
     * comportamento esperado, não um bug. A categoria em comum ainda precisa
     * ser aceita pelo apply para essa direção (CategoryDirection, a mesma
     * checagem de ApplyCategorizationRequest), senão aceitar a sugestão
     * sempre daria erro de validação.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, int>  $historyMemo
     * @param  Collection<int, Category>  $suggestable
     */
    private function suggestion(Collection $transactions, array $historyMemo, Collection $suggestable): ?int
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

        if ($categoryIds->count() !== 1) {
            return null;
        }

        $category = $suggestable->get($categoryIds->first());

        return $category !== null && CategoryDirection::accepts($category, $direction) ? $category->id : null;
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
            originalDescription: $transaction->original_description,
            amount: $transaction->amount->cents,
            direction: $transaction->direction,
            accountId: $transaction->account_id,
            accountName: $transaction->account->name,
        );
    }
}
