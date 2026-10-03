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
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aplica as decisões do IngestionPlanner num lote, numa única transação de
 * banco: um erro no meio não deixa nada gravado. Cada desfecho já vem
 * decidido (ver RowOutcome); aqui só grava e, para as linhas que tocam uma
 * transação existente, guarda o "desfazer" com os atributos que de fato
 * mudaram, para o RevertImportBatch restaurar exatamente o estado anterior.
 */
final class IngestTransactions
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly StatementResolver $statementResolver,
        private readonly CategorizeTransaction $categorize,
    ) {}

    /**
     * @param  list<RowDecision>  $decisions
     * @return array{stats: array<string, int>, undo: list<array{transaction_id: int, attributes: array<string, mixed>}>}
     *
     * @throws ImportBatchNotPending
     */
    public function handle(ImportBatch $batch, Account $account, array $decisions): array
    {
        if ($batch->status !== ImportBatchStatus::Pending) {
            throw new ImportBatchNotPending;
        }

        return DB::transaction(function () use ($batch, $account, $decisions) {
            $stats = [
                'inserted' => 0,
                'duplicates' => 0,
                'updated' => 0,
                'replaced' => 0,
                'adopted' => 0,
                'swapped' => 0,
            ];
            $undo = [];

            foreach ($decisions as $decision) {
                match ($decision->outcome) {
                    RowOutcome::New => $this->insertNew($batch, $account, $decision->row, $stats),
                    RowOutcome::Duplicate => $stats['duplicates']++,
                    RowOutcome::Update => $this->update($decision, $undo, $stats),
                    RowOutcome::ReplaceInstallment => $this->replaceInstallment($decision, $batch, $undo, $stats),
                    RowOutcome::Adopt => $this->adopt($decision, $batch, $undo, $stats),
                    RowOutcome::SwapPending => $this->swapPending($decision, $undo, $stats),
                };
            }

            return ['stats' => $stats, 'undo' => $undo];
        });
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function insertNew(ImportBatch $batch, Account $account, ParsedRow $row, array &$stats): void
    {
        $plan = $row->installment !== null && $account->isCreditCard()
            ? $this->createInstallmentPlan($batch, $account, $row)
            : null;

        $transaction = new Transaction([
            'account_id' => $account->id,
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

        $this->categorize->handleImported($transaction);

        if ($plan !== null) {
            $this->projectRemainingInstallments($batch, $account, $plan, $row, $transaction);
        }

        $stats['inserted']++;
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
     * (StatementResolver::next), lançada se a data já chegou e com a mesma
     * categoria/categorized_by que a parcela N recebeu da categorização.
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

        for ($number = $installment['number'] + 1; $number <= $installment['total']; $number++) {
            $statement = $this->statementResolver->next($account, $statement);
            $date = $rowDate->addMonthsNoOverflow($number - $installment['number']);

            Transaction::create([
                'account_id' => $account->id,
                'date' => $date,
                'amount' => $row->amount,
                'direction' => $row->direction,
                'currency' => $account->currency,
                'description' => $row->description,
                'original_description' => $row->description,
                'status' => $date->lessThanOrEqualTo($today) ? TransactionStatus::Posted : TransactionStatus::Projected,
                'source' => TransactionSource::Installment,
                'statement_id' => $statement->id,
                'installment_plan_id' => $plan->id,
                'installment_number' => $number,
                'import_batch_id' => $batch->id,
                'category_id' => $parcel->category_id,
                'categorized_by' => $parcel->categorized_by,
            ]);
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
     * Parcela de plano (projetada ou já lançada) que a linha do banco
     * confirma: fica posted com a data real e ganha external_id/source. O
     * undo só guarda o que de fato muda (uma parcela já posted, por
     * exemplo, não gera entrada de "status" no undo).
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    private function replaceInstallment(RowDecision $decision, ImportBatch $batch, array &$undo, array &$stats): void
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

        $dateChanged = $transaction->date->toDateString() !== $decision->row->date;
        if ($dateChanged) {
            $attributes['date'] = $transaction->date->toDateString();
        }

        $transaction->external_id = $decision->row->externalId;
        $transaction->source = $newSource;
        $transaction->status = TransactionStatus::Posted;
        $transaction->date = CarbonImmutable::parse($decision->row->date);

        if ($dateChanged) {
            $previousStatementId = $transaction->statement_id;
            $this->assignStatement->handle($transaction);

            if ($transaction->statement_id !== $previousStatementId) {
                $attributes['statement_id'] = $previousStatementId;
            }
        }

        if ($attributes !== []) {
            $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $attributes];
        }

        $transaction->save();
        $stats['replaced']++;
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
