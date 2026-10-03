<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
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
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Imports\Support\UndoSnapshot;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
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
            $decisions = $this->planner->plan($account, $rows);

            $rules = Rule::query()->where('is_active', true)->ordered()->get()
                ->map(RuleDefinition::fromRule(...))
                ->all();

            // Uma consulta (ou poucas, em lotes de até 1000 chaves) para todo
            // o histórico das linhas novas deste lote, em vez de uma consulta
            // por transação inserida (ver HistoryCategorizer::suggestMany()).
            $historyMemo = $this->history->suggestMany($this->newRowHistoryPairs($decisions));

            [$stats, $undo] = $this->applyDecisions($locked, $account, $decisions, $rules, $historyMemo);

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
     * @return array{0: array<string, int>, 1: list<array{transaction_id: int, attributes: array<string, mixed>}>}
     */
    private function applyDecisions(ImportBatch $batch, Account $account, array $decisions, array $rules, array $historyMemo): array
    {
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

        foreach ($decisions as $index => $decision) {
            match ($decision->outcome) {
                RowOutcome::New => $this->insertNew($batch, $account, $decision->row, $rules, $historyMemo, $stats, $plansBySeedIndex, $index),
                RowOutcome::Duplicate => $stats['duplicates']++,
                RowOutcome::Update => $this->update($decision, $undo, $stats),
                RowOutcome::ReplaceInstallment => $decision->transactionId !== null
                    ? $this->replaceInstallmentOutcome($decision, $batch, $undo, $stats)
                    : $deferred[] = $decision,
                RowOutcome::Adopt => $this->adopt($decision, $batch, $undo, $stats),
                RowOutcome::SwapPending => $this->swapPending($decision, $undo, $stats),
            };
        }

        foreach ($deferred as $decision) {
            $this->replaceSeededParcel($decision, $batch, $plansBySeedIndex, $undo, $stats);
        }

        return [$stats, $undo];
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
     * @param  array<string, int>  $stats
     * @param  array<int, InstallmentPlan>  $plansBySeedIndex
     */
    private function insertNew(
        ImportBatch $batch,
        Account $account,
        ParsedRow $row,
        array $rules,
        array $historyMemo,
        array &$stats,
        array &$plansBySeedIndex,
        int $index,
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
            'status' => $row->pending ? TransactionStatus::Pending : TransactionStatus::Posted,
            'source' => $batch->format->source(),
            'import_batch_id' => $batch->id,
            'installment_plan_id' => $plan?->id,
            'installment_number' => $plan !== null ? $row->installment['number'] : null,
        ]);

        $this->assignStatement->handle($transaction);
        $transaction->save();

        $this->categorize->handleImported($transaction, $rules, $historyMemo);

        if ($plan !== null) {
            $plansBySeedIndex[$index] = $plan;
            $this->projectInstallments->projectRemaining($batch, $account, $plan, $row, $transaction);
        }

        $stats['inserted']++;
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

        $this->replaceParcel($parcel, $decision->row, $batch, $undo);
        $stats['replaced']++;
    }

    /**
     * @param  list<RowDecision>  $decisions
     * @return list<array{description_key: string, direction: string}>
     */
    private function newRowHistoryPairs(array $decisions): array
    {
        $pairs = [];

        foreach ($decisions as $decision) {
            if ($decision->outcome !== RowOutcome::New) {
                continue;
            }

            $pairs[] = [
                'description_key' => TextNormalizer::key($decision->row->description),
                'direction' => $decision->row->direction->value,
            ];
        }

        return $pairs;
    }

    /**
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function update(RowDecision $decision, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $dateChanged = $transaction->date->toDateString() !== $decision->row->date;
        $previousStatementId = $transaction->statement_id;

        $attributes = [
            'status' => TransactionStatus::Posted,
            'date' => CarbonImmutable::parse($decision->row->date),
        ];

        // Pernas de transferência e parcelas de plano têm o valor travado
        // por outras regras do domínio (simetria da transferência, total do
        // parcelamento travado): a importação nunca sobrescreve isso.
        if ($transaction->transfer_id === null && $transaction->installment_plan_id === null) {
            $attributes['amount'] = Money::cents($decision->row->amount);
        }

        $changed = UndoSnapshot::applyAndDiff($transaction, $attributes);

        if ($dateChanged) {
            $this->assignStatement->handle($transaction);

            if ($transaction->statement_id !== $previousStatementId) {
                $changed['statement_id'] = $previousStatementId;
            }
        }

        if ($changed !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $changed];
        }

        $transaction->save();
        $stats['updated']++;
    }

    /**
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function replaceInstallmentOutcome(RowDecision $decision, ImportBatch $batch, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $this->replaceParcel($transaction, $decision->row, $batch, $undo);
        $stats['replaced']++;
    }

    /**
     * Confirma uma parcela de plano — já existente no banco
     * (ReplaceInstallment) ou criada mais cedo neste mesmo lote
     * (replaceSeededParcel): fica posted com a data e o valor reais, e
     * ganha external_id/source. statement_id nunca muda aqui: é a
     * sequência do próprio plano (StatementResolver::next a partir da
     * fatura da parcela anterior) que decide a fatura de cada parcela —
     * mais confiável do que recalcular pela data que o banco informou
     * agora, que pode cair perto do fechamento de um ciclo vizinho.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     */
    private function replaceParcel(Transaction $transaction, ParsedRow $row, ImportBatch $batch, array &$undo): void
    {
        $changed = UndoSnapshot::applyAndDiff($transaction, [
            'external_id' => $row->externalId,
            'source' => $batch->format->source(),
            'status' => TransactionStatus::Posted,
            'date' => CarbonImmutable::parse($row->date),
            // O valor estimado (plano ÷ N ou o da própria linha semente)
            // pode diferir do que o banco de fato cobrou nesta parcela
            // (juros, arredondamento): adota o valor real para a fatura
            // bater com o banco.
            'amount' => Money::cents($row->amount),
        ]);

        if ($changed !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $changed];
        }

        $transaction->save();
    }

    /**
     * Lançamento manual que a linha do banco confirma: mantém categoria,
     * descrição, notas e tags; ganha external_id/source/status/original_description.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function adopt(RowDecision $decision, ImportBatch $batch, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);

        $changed = UndoSnapshot::applyAndDiff($transaction, [
            'external_id' => $decision->row->externalId,
            'source' => $batch->format->source(),
            'status' => TransactionStatus::Posted,
            'original_description' => $decision->row->description,
        ]);

        if ($changed !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $changed];
        }

        $transaction->save();
        $stats['adopted']++;
    }

    /**
     * Pending da mesma conta cujo external_id o banco trocou: só troca o id
     * e vira posted.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function swapPending(RowDecision $decision, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);

        $changed = UndoSnapshot::applyAndDiff($transaction, [
            'external_id' => $decision->row->externalId,
            'status' => TransactionStatus::Posted,
        ]);

        if ($changed !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $changed];
        }

        $transaction->save();
        $stats['swapped']++;
    }
}
