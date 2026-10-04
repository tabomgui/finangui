<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Support\ProviderCategoryMatcher;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\ImportedInstallments;
use App\Domain\Imports\Support\ImportPreloads;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\DetectTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lote completo de importação: trava a conta e o próprio lote, roda o
 * IngestionPlanner já dentro da trava (ninguém mais decide em cima de um
 * estado que pode mudar debaixo do pé), aplica cada decisão e fecha o lote
 * (stats, undo, faturas criadas, status completed) — tudo numa única
 * transação de banco, então um erro no meio não deixa nada gravado.
 */
final class IngestTransactions
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly CategorizeTransaction $categorize,
        private readonly IngestionPlanner $planner,
        private readonly HistoryCategorizer $history,
        private readonly ProjectInstallments $projectInstallments,
        private readonly MatchedTransactionOutcomes $matchedOutcomes,
        private readonly DetectTransfers $detectTransfers,
    ) {}

    /**
     * @param  list<ParsedRow>  $rows  linhas já filtradas (sem as desmarcadas na prévia)
     * @param  array<string, mixed>  $extraStats  o que o planner não decide: failed (do parse) e skipped (desmarcadas), mescladas ao stats final
     *
     * @throws ImportBatchNotPending
     */
    public function handle(ImportBatch $batch, array $rows, array $extraStats = []): ImportBatch
    {
        return DB::transaction(function () use ($batch, $rows, $extraStats) {
            // Trava a conta: serializa com qualquer outro lote/ação da mesma
            // conta que também crie fatura (AssignStatement/StatementResolver
            // já travam a mesma linha), e com um segundo ingest do mesmo lote.
            $account = Account::query()->whereKey($batch->account_id)->lockForUpdate()->firstOrFail();

            // Relê o próprio lote sob trava: dois ingests concorrentes do
            // mesmo lote (ex.: duplo clique em "confirmar") nunca passam
            // juntos — o segundo acha status já completed e falha.
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ImportBatchStatus::Pending) {
                throw new ImportBatchNotPending;
            }

            // Marco d'água das faturas já existentes: qualquer CardStatement
            // com id maior, ao final, foi criado por este lote (a conta fica
            // travada daqui até o fim, então nenhuma outra sessão cria uma
            // fatura por baixo enquanto isso).
            $statementWatermark = CardStatement::query()->where('account_id', $account->id)->max('id') ?? 0;

            // Decidido só agora, com a conta travada: a mesma leitura que a
            // prévia fez pode ter ficado desatualizada entre a prévia e a
            // confirmação.
            $decisions = $this->planner->plan($account, $rows, $locked->format);

            $rules = Rule::query()->where('is_active', true)->ordered()->get()
                ->map(RuleDefinition::fromRule(...))
                ->all();

            // Uma consulta (ou poucas, em lotes de até 1000 chaves) para todo
            // o histórico das linhas novas deste lote, em vez de uma consulta
            // por transação inserida (ver HistoryCategorizer::suggestMany()).
            $historyMemo = $this->history->suggestMany(ImportPreloads::historyPairs($decisions));

            // Idem para a fatura do banco (meta.bill_id) e para a categoria
            // do provedor (meta.provider_category): uma consulta para o
            // lote inteiro, não uma por linha nova.
            $billStatementMemo = ImportPreloads::billStatements($account, $decisions);
            $usableCategoriesByName = ImportPreloads::usableCategoriesByName($decisions);

            [$stats, $undo, $insertedIds] = $this->applyDecisions($locked, $account, $decisions, $rules, $historyMemo, $billStatementMemo, $usableCategoriesByName);

            // Detecção de transferência só sobre as transações que este
            // lote de fato inseriu (RowOutcome::New) — ainda dentro da
            // trava da conta e do lote, depois de categorizar: ligar ou
            // sugerir usa a categoria/descrição já resolvidas.
            $detection = $insertedIds !== [] ? $this->detectTransfers->handle($insertedIds) : ['linked' => 0, 'suggested' => 0, 'undo' => []];
            $stats['transfers_linked'] = $detection['linked'];
            $stats['transfer_suggestions'] = $detection['suggested'];

            // A perna de fora do lote que a detecção ligou automaticamente
            // (ver DetectTransfers::applyLinks()) entra no mesmo undo do
            // lote: RevertImportBatch restaura a categoria/fatura que ela
            // tinha antes, não deixa sem categoria nem com uma nova só pelo
            // acaso da ligação ter sido desfeita.
            $undo = [...$undo, ...$detection['undo']];

            $createdStatementIds = CardStatement::query()
                ->where('account_id', $account->id)
                ->where('id', '>', $statementWatermark)
                ->pluck('id')
                ->all();

            $locked->stats = array_merge($stats, $extraStats);
            $locked->undo = $undo;
            $locked->created_statement_ids = $createdStatementIds;
            $locked->status = ImportBatchStatus::Completed;
            $locked->completed_at = CarbonImmutable::now();
            $locked->rows = null;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Uma passada pelas decisões (na ordem do arquivo) aplica tudo, exceto
     * a "semente dentro do próprio lote" (ReplaceInstallment sem
     * transaction): essa é adiada para depois, porque a semente pode
     * aparecer mais tarde no arquivo do que quem a usa (ex.: "parcela 3/10"
     * antes de "2/10" no arquivo — ImportedInstallments::classify() ainda
     * assim escolhe a 2/10, de menor número, como semente).
     *
     * @param  list<RowDecision>  $decisions
     * @param  list<RuleDefinition>  $rules
     * @param  array<string, int>  $historyMemo
     * @param  array<string, int>  $billStatementMemo  external_id da fatura → id do CardStatement (ver preloadBillStatements())
     * @param  array<string, list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>>  $usableCategoriesByName  categorias ativas do usuário por nome normalizado (ver preloadUsableCategoriesByName())
     * @return array{0: array<string, int>, 1: list<array{transaction_id: int, attributes: array<string, mixed>}>, 2: list<int>}
     */
    private function applyDecisions(
        ImportBatch $batch,
        Account $account,
        array $decisions,
        array $rules,
        array $historyMemo,
        array $billStatementMemo,
        array $usableCategoriesByName,
    ): array {
        $stats = [
            'inserted' => 0,
            'duplicates' => 0,
            'updated' => 0,
            'replaced' => 0,
            'adopted' => 0,
            'swapped' => 0,
        ];
        $undo = [];
        /** @var array<int, InstallmentPlan> $plansBySeedIndex */
        $plansBySeedIndex = [];
        /** @var list<RowDecision> $deferred */
        $deferred = [];
        /** @var list<int> $insertedIds */
        $insertedIds = [];

        foreach ($decisions as $index => $decision) {
            match ($decision->outcome) {
                RowOutcome::New => $this->insertNew($batch, $account, $decision->row, $rules, $historyMemo, $billStatementMemo, $usableCategoriesByName, $stats, $plansBySeedIndex, $index, $insertedIds),
                RowOutcome::Duplicate => $stats['duplicates']++,
                RowOutcome::Update => $this->matchedOutcomes->update($decision, $undo, $stats),
                RowOutcome::ReplaceInstallment => $decision->transactionId !== null
                    ? $this->matchedOutcomes->replaceInstallmentOutcome($decision, $batch, $undo, $stats)
                    : $deferred[] = $decision,
                RowOutcome::Adopt => $this->matchedOutcomes->adopt($decision, $batch, $undo, $stats),
                RowOutcome::SwapPending => $this->matchedOutcomes->swapPending($decision, $undo, $stats),
            };
        }

        foreach ($deferred as $decision) {
            $this->replaceSeededParcel($decision, $batch, $plansBySeedIndex, $undo, $stats);
        }

        return [$stats, $undo, $insertedIds];
    }

    /**
     * Linha nova "de verdade": o IngestionPlanner só devolve New para uma
     * parcela quando nem o banco (ReplaceInstallment com transaction) nem
     * nenhuma linha anterior deste mesmo arquivo (ReplaceInstallment sem
     * transaction, ver replaceSeededParcel()) já cobrem esta compra — então
     * aqui sempre cria o plano quando é parcela.
     *
     * @param  list<RuleDefinition>  $rules
     * @param  array<string, int>  $historyMemo
     * @param  array<string, int>  $billStatementMemo  external_id da fatura → id do CardStatement (ver preloadBillStatements())
     * @param  array<string, list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>>  $usableCategoriesByName  ver preloadUsableCategoriesByName()
     * @param  array<string, int>  $stats
     * @param  array<int, InstallmentPlan>  $plansBySeedIndex
     * @param  list<int>  $insertedIds
     */
    private function insertNew(
        ImportBatch $batch,
        Account $account,
        ParsedRow $row,
        array $rules,
        array $historyMemo,
        array $billStatementMemo,
        array $usableCategoriesByName,
        array &$stats,
        array &$plansBySeedIndex,
        int $index,
        array &$insertedIds,
    ): void {
        $isInstallment = ImportedInstallments::isInstallmentRow($account, $row);
        $plan = $isInstallment ? $this->projectInstallments->createPlan($batch, $account, $row) : null;

        $transaction = new Transaction([
            'account_id' => $account->id,
            // Parcela nova (plano criado agora): confia na data informada
            // pela linha como a data real da parcela — diferente de uma
            // parcela que já existia (replaceParcel), onde a fatura já
            // atribuída pela sequência do plano é que manda.
            'date' => $row->date,
            'amount' => $row->amount,
            'direction' => $row->direction,
            'currency' => $account->currency,
            'description' => $row->description,
            'original_description' => $row->description,
            'external_id' => $row->externalId,
            // ParsedRow::status(): pendente datada no futuro (ex.: parcela
            // de cartão que o banco já relata mas não lançou) vira
            // Projected, não Pending.
            'status' => $row->status(),
            'source' => $batch->format->source(),
            'import_batch_id' => $batch->id,
            'installment_plan_id' => $plan?->id,
            'installment_number' => $plan !== null ? $row->installment['number'] : null,
        ]);

        // Fatura do banco (ex.: Pluggy): se a linha aponta um bill_id e já
        // existe uma fatura local com esse external_id, usa essa fatura
        // específica em vez da regra de data padrão do AssignStatement.
        $billId = $row->meta['bill_id'] ?? null;
        $statementId = is_string($billId) ? ($billStatementMemo[$billId] ?? null) : null;

        $this->assignStatement->handle($transaction, $statementId);
        $transaction->save();

        $this->categorize->handleImported($transaction, $rules, $historyMemo);

        $this->applyProviderCategory($transaction, $row, $usableCategoriesByName);

        if ($plan !== null) {
            $plansBySeedIndex[$index] = $plan;
            $this->projectInstallments->projectRemaining($batch, $account, $plan, $row, $transaction);
        }

        $stats['inserted']++;
        $insertedIds[] = $transaction->id;
    }

    /**
     * Terceiro passo da categorização de uma linha nova importada do banco
     * — só depois de regras e histórico (handleImported(), chamado antes
     * disso, já cobre os dois primeiros): se a transação ainda não tem
     * categoria e a linha carrega a categoria do provedor (meta.provider_category,
     * ver TransactionMapper), usa ProviderCategoryMatcher pra casar pelo
     * nome com uma categoria ativa do usuário. Sem match, não categoriza.
     *
     * @param  array<string, list<array{id: int, kind: string, is_transfer: bool, has_parent: bool}>>  $usableCategoriesByName
     */
    private function applyProviderCategory(Transaction $transaction, ParsedRow $row, array $usableCategoriesByName): void
    {
        if ($transaction->category_id !== null) {
            return;
        }

        $providerCategory = $row->meta['provider_category'] ?? null;

        if (! is_array($providerCategory) || ! isset($providerCategory['name'])) {
            return;
        }

        /** @var array{name: string, parent: string|null} $providerCategory */
        $categoryId = ProviderCategoryMatcher::match($providerCategory, $usableCategoriesByName, $transaction->direction);

        if ($categoryId === null) {
            return;
        }

        $transaction->category_id = $categoryId;
        $transaction->categorized_by = 'pluggy';
        $transaction->save();
    }

    /**
     * Parcela de uma compra nova cuja primeira ocorrência (a de menor
     * número, ver ImportedInstallments::classify()), neste mesmo arquivo,
     * já criou o plano: substitui a parcela projetada que insertNew() já
     * deixou pronta para este número.
     *
     * @param  array<int, InstallmentPlan>  $plansBySeedIndex
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function replaceSeededParcel(RowDecision $decision, ImportBatch $batch, array $plansBySeedIndex, array &$undo, array &$stats): void
    {
        $plan = $decision->seedIndex !== null ? ($plansBySeedIndex[$decision->seedIndex] ?? null) : null;

        if ($plan === null) {
            // Não deveria acontecer: o planner usa a mesma classificação
            // antes de decidir isto. Falha alto em vez de inserir uma
            // parcela "nova" por engano, que duplicaria o plano.
            throw new RuntimeException("Linha {$decision->row->line}: parcela esperada no lote não foi encontrada.");
        }

        /** @var array{number: int, total: int} $installment */
        $installment = $decision->row->installment;
        $parcel = Transaction::query()
            ->where('installment_plan_id', $plan->id)
            ->where('installment_number', $installment['number'])
            ->firstOrFail();

        $this->matchedOutcomes->replaceParcel($parcel, $decision->row, $batch, $undo);
        $stats['replaced']++;
    }
}
