<?php

namespace App\Domain\Imports\Queries;

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Data\ImportPreviewData;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Models\Transaction;

/**
 * Monta a prévia de um lote: roda o IngestionPlanner sobre as linhas
 * guardadas (lote pendente só) e, para a exibição, resolve a categoria
 * sugerida de cada linha nova e os dados da transação que cada decisão
 * não-nova aponta. Usado tanto no upload (lote recém-criado) quanto num GET
 * de um lote pendente mais tarde — os dois caminhos passam por aqui, para
 * nunca mostrar números diferentes.
 *
 * Regras ativas, categorias usáveis e o histórico são carregados uma única
 * vez para o lote inteiro (nunca por linha): ver
 * HistoryCategorizer::suggestMany() e os parâmetros opcionais de
 * CategorizeTransaction::suggest().
 */
final class ImportPreview
{
    public function __construct(
        private readonly IngestionPlanner $planner,
        private readonly CategorizeTransaction $categorize,
        private readonly HistoryCategorizer $history,
    ) {}

    public function for(ImportBatch $batch): ImportPreviewData
    {
        $batch->loadMissing('account');

        if ($batch->status !== ImportBatchStatus::Pending) {
            return new ImportPreviewData($batch);
        }

        /** @var Account $account */
        $account = $batch->account;

        /** @var list<array<string, mixed>> $storedRows */
        $storedRows = $batch->rows ?? [];
        $rows = array_map(ParsedRow::fromArray(...), $storedRows);
        $decisions = $this->planner->plan($account, $rows, $batch->format);

        return new ImportPreviewData(
            $batch,
            $decisions,
            $this->suggestions($account, $decisions),
            $this->matches($decisions),
        );
    }

    /**
     * @param  list<RowDecision>  $decisions
     * @return array<int, int>
     */
    private function suggestions(Account $account, array $decisions): array
    {
        $newDecisions = array_values(array_filter(
            $decisions,
            fn (RowDecision $decision) => $decision->outcome === RowOutcome::New,
        ));

        if ($newDecisions === []) {
            return [];
        }

        $rules = Rule::query()->where('is_active', true)->ordered()->get()
            ->map(RuleDefinition::fromRule(...))
            ->all();
        $usableCategoryIds = Category::query()->usable()->pluck('id')->all();

        $historyMemo = $this->history->suggestMany(array_map(
            fn (RowDecision $decision) => [
                'description_key' => TextNormalizer::key($decision->row->description),
                'direction' => $decision->row->direction->value,
            ],
            $newDecisions,
        ));

        $suggestions = [];

        foreach ($newDecisions as $decision) {
            $row = $decision->row;

            $transaction = new Transaction([
                'account_id' => $account->id,
                'date' => $row->date,
                'amount' => $row->amount,
                'direction' => $row->direction,
                'currency' => $account->currency,
                'description' => $row->description,
                'original_description' => $row->description,
            ]);

            $suggestion = $this->categorize->suggest($transaction, $rules, $usableCategoryIds, $historyMemo);

            if ($suggestion !== null) {
                $suggestions[$row->line] = $suggestion['category_id'];
            }
        }

        return $suggestions;
    }

    /**
     * Dados (id, data, descrição) das transações que decisões diferentes de
     * "new" apontam: o IngestionPlanner já carregou o model de cada uma
     * junto da decisão (ver RowDecision::$transaction), então não precisa
     * de outra consulta aqui. `kind` distingue, para a prévia, uma prevista
     * de recorrência casada por RecurrenceMatcher ("Casa com lançamento
     * previsto") de um lançamento manual comum.
     *
     * @param  list<RowDecision>  $decisions
     * @return array<int, array{id: int, date: string, description: string, kind: 'manual'|'recurrence'}>
     */
    private function matches(array $decisions): array
    {
        $matches = [];

        foreach ($decisions as $decision) {
            if ($decision->transaction === null) {
                continue;
            }

            $transaction = $decision->transaction;

            $matches[$transaction->id] = [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'description' => $transaction->description,
                'kind' => $decision->matchedByRecurrence ? 'recurrence' : 'manual',
            ];
        }

        return $matches;
    }
}
