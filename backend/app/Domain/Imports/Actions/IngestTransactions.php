<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        private readonly StatementResolver $statementResolver,
        private readonly CategorizeTransaction $categorize,
        private readonly IngestionPlanner $planner,
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

            $stats = [
                'inserted' => 0,
                'duplicates' => 0,
                'updated' => 0,
                'replaced' => 0,
                'adopted' => 0,
                'swapped' => 0,
            ];
            $undo = [];
            // Planos criados neste próprio lote: uma segunda (ou terceira...)
            // parcela nova do mesmo parcelamento, no mesmo arquivo, ainda não
            // existe no banco para o planner casar como ReplaceInstallment —
            // sem isso, cada parcela nova do mesmo parcelamento criaria o seu
            // próprio plano. Ver matchPlanCreatedThisBatch().
            $plansThisBatch = [];

            foreach ($decisions as $decision) {
                match ($decision->outcome) {
                    RowOutcome::New => $this->insertNew($locked, $account, $decision->row, $rules, $stats, $undo, $plansThisBatch),
                    RowOutcome::Duplicate => $stats['duplicates']++,
                    RowOutcome::Update => $this->update($decision, $undo, $stats),
                    RowOutcome::ReplaceInstallment => $this->replaceInstallmentOutcome($decision, $locked, $undo, $stats),
                    RowOutcome::Adopt => $this->adopt($decision, $locked, $undo, $stats),
                    RowOutcome::SwapPending => $this->swapPending($decision, $undo, $stats),
                };
            }

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
     * @param  list<RuleDefinition>  $rules
     * @param  array<string, int>  $stats
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  list<array{plan: InstallmentPlan, descriptionKey: string, total: int, amount: int}>  $plansThisBatch
     */
    private function insertNew(
        ImportBatch $batch,
        Account $account,
        ParsedRow $row,
        array $rules,
        array &$stats,
        array &$undo,
        array &$plansThisBatch,
    ): void {
        // Só conta credit_card e só saída: a mesma condição que o planner usa
        // para tentar casar com uma parcela existente (ver IngestionPlanner).
        $isInstallment = $row->installment !== null && $account->isCreditCard() && $row->direction === Direction::Out;

        if ($isInstallment) {
            $matchedPlan = $this->matchPlanCreatedThisBatch($plansThisBatch, $row);

            if ($matchedPlan !== null) {
                /** @var array{number: int, total: int} $installment */
                $installment = $row->installment;
                $parcel = Transaction::query()
                    ->where('installment_plan_id', $matchedPlan['plan']->id)
                    ->where('installment_number', $installment['number'])
                    ->firstOrFail();

                $this->replaceParcel($parcel, $row, $batch, $undo);
                $stats['replaced']++;

                return;
            }
        }

        $plan = $isInstallment ? $this->createInstallmentPlan($batch, $account, $row) : null;

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

        $this->categorize->handleImported($transaction, $rules);

        if ($plan !== null) {
            /** @var array{number: int, total: int} $installment */
            $installment = $row->installment;
            $plansThisBatch[] = [
                'plan' => $plan,
                'descriptionKey' => TextNormalizer::key($row->description),
                'total' => $installment['total'],
                'amount' => $row->amount,
            ];
            $this->projectRemainingInstallments($batch, $account, $plan, $row, $transaction);
        }

        $stats['inserted']++;
    }

    /**
     * Acha, entre os planos que este próprio lote já criou, um cuja parcela
     * bateria com a linha: mesmo total, mesma chave de descrição e valor com
     * diferença menor que o total de parcelas (mesma tolerância do
     * IngestionPlanner::matchInstallment, ver ali).
     *
     * @param  list<array{plan: InstallmentPlan, descriptionKey: string, total: int, amount: int}>  $plansThisBatch
     * @return array{plan: InstallmentPlan, descriptionKey: string, total: int, amount: int}|null
     */
    private function matchPlanCreatedThisBatch(array $plansThisBatch, ParsedRow $row): ?array
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;
        $descriptionKey = TextNormalizer::key($row->description);

        foreach ($plansThisBatch as $entry) {
            if ($entry['total'] !== $installment['total'] || $entry['descriptionKey'] !== $descriptionKey) {
                continue;
            }

            if (abs($entry['amount'] - $row->amount) >= $entry['total']) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    /**
     * purchase_date estimada: a data da linha menos (N−1) meses, sem
     * overflow (ex.: parcela 2/10 lançada em 31/03 "nasceu" em 28 ou 29/02).
     */
    private function createInstallmentPlan(ImportBatch $batch, Account $account, ParsedRow $row): InstallmentPlan
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;

        return InstallmentPlan::create([
            'account_id' => $account->id,
            'description' => $row->description,
            'installments' => $installment['total'],
            'purchase_date' => CarbonImmutable::parse($row->date)->subMonthsNoOverflow($installment['number'] - 1),
            'total_amount' => $row->amount * $installment['total'],
            'import_batch_id' => $batch->id,
        ]);
    }

    /**
     * Projeta as parcelas N+1..M (a linha importada já gravou a parcela N):
     * mesmo valor da linha, uma fatura seguinte por parcela
     * (StatementResolver::next), lançada se a data já chegou, e herdando da
     * parcela N tudo que a categorização/regras podem ter mudado nela
     * (descrição, favorecido, ignorada, categoria/categorized_by e tags) —
     * original_description continua sendo a da própria linha de cada
     * parcela, não a da parcela N.
     */
    private function projectRemainingInstallments(
        ImportBatch $batch,
        Account $account,
        InstallmentPlan $plan,
        ParsedRow $row,
        Transaction $parcel,
    ): void {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;
        $today = CarbonImmutable::today();
        $rowDate = CarbonImmutable::parse($row->date);
        $statement = CardStatement::query()->findOrFail($parcel->statement_id);
        $tagIds = $parcel->tags->pluck('id')->all();

        for ($number = $installment['number'] + 1; $number <= $installment['total']; $number++) {
            $statement = $this->statementResolver->next($account, $statement);
            $date = $rowDate->addMonthsNoOverflow($number - $installment['number']);

            $projected = Transaction::create([
                'account_id' => $account->id,
                'date' => $date,
                'amount' => $row->amount,
                'direction' => $row->direction,
                'currency' => $account->currency,
                'description' => $parcel->description,
                'original_description' => $row->description,
                'payee' => $parcel->payee,
                'is_ignored' => $parcel->is_ignored,
                'status' => $date->lessThanOrEqualTo($today) ? TransactionStatus::Posted : TransactionStatus::Projected,
                'source' => TransactionSource::Installment,
                'statement_id' => $statement->id,
                'installment_plan_id' => $plan->id,
                'installment_number' => $number,
                'import_batch_id' => $batch->id,
                'category_id' => $parcel->category_id,
                'categorized_by' => $parcel->categorized_by,
            ]);

            if ($tagIds !== []) {
                $projected->tags()->sync($tagIds);
            }
        }
    }

    /**
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function update(RowDecision $decision, array &$undo, array &$stats): void
    {
        $transaction = Transaction::query()->findOrFail($decision->transactionId);

        $undo[] = [
            'transaction_id' => $transaction->id,
            'attributes' => [
                'status' => $transaction->status->value,
                'amount' => $transaction->amount->cents,
                'date' => $transaction->date->toDateString(),
                'statement_id' => $transaction->statement_id,
            ],
        ];

        $dateChanged = $transaction->date->toDateString() !== $decision->row->date;

        $transaction->status = TransactionStatus::Posted;
        $transaction->amount = Money::cents($decision->row->amount);
        $transaction->date = CarbonImmutable::parse($decision->row->date);

        if ($dateChanged) {
            $this->assignStatement->handle($transaction);
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
        $transaction = Transaction::query()->findOrFail($decision->transactionId);
        $this->replaceParcel($transaction, $decision->row, $batch, $undo);
        $stats['replaced']++;
    }

    /**
     * Confirma uma parcela de plano — já existente no banco (ReplaceInstallment)
     * ou criada mais cedo neste mesmo lote (matchPlanCreatedThisBatch): fica
     * posted com a data real e ganha external_id/source. statement_id nunca
     * muda aqui: é a sequência do próprio plano (StatementResolver::next a
     * partir da fatura da parcela anterior) que decide a fatura de cada
     * parcela — mais confiável do que recalcular pela data que o banco
     * informou agora, que pode cair perto do fechamento de um ciclo vizinho.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     */
    private function replaceParcel(Transaction $transaction, ParsedRow $row, ImportBatch $batch, array &$undo): void
    {
        $attributes = [];

        if ($transaction->external_id !== $row->externalId) {
            $attributes['external_id'] = $transaction->external_id;
        }

        $newSource = $batch->format->source();
        if ($transaction->source !== $newSource) {
            $attributes['source'] = $transaction->source->value;
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            $attributes['status'] = $transaction->status->value;
        }

        if ($transaction->date->toDateString() !== $row->date) {
            $attributes['date'] = $transaction->date->toDateString();
        }

        $transaction->external_id = $row->externalId;
        $transaction->source = $newSource;
        $transaction->status = TransactionStatus::Posted;
        $transaction->date = CarbonImmutable::parse($row->date);

        if ($attributes !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $attributes];
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
        $transaction = Transaction::query()->findOrFail($decision->transactionId);
        $attributes = [];

        if ($transaction->external_id !== $decision->row->externalId) {
            $attributes['external_id'] = $transaction->external_id;
        }

        $newSource = $batch->format->source();
        if ($transaction->source !== $newSource) {
            $attributes['source'] = $transaction->source->value;
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            $attributes['status'] = $transaction->status->value;
        }

        if ($transaction->original_description !== $decision->row->description) {
            $attributes['original_description'] = $transaction->original_description;
        }

        if ($attributes !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $attributes];
        }

        $transaction->external_id = $decision->row->externalId;
        $transaction->source = $newSource;
        $transaction->status = TransactionStatus::Posted;
        $transaction->original_description = $decision->row->description;
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
        $transaction = Transaction::query()->findOrFail($decision->transactionId);
        $attributes = [];

        if ($transaction->external_id !== $decision->row->externalId) {
            $attributes['external_id'] = $transaction->external_id;
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            $attributes['status'] = $transaction->status->value;
        }

        if ($attributes !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $attributes];
        }

        $transaction->external_id = $decision->row->externalId;
        $transaction->status = TransactionStatus::Posted;
        $transaction->save();

        $stats['swapped']++;
    }
}
