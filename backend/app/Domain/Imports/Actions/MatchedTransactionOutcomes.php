<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\UndoSnapshot;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Os desfechos de IngestTransactions que tocam uma transação que já existe
 * (update/replace/adopt/swap_pending) — extraído só para IngestTransactions
 * não crescer. Cada método grava, em `$undo`, só os atributos que de fato
 * mudaram (ver UndoSnapshot), para RevertImportBatch restaurar exatamente
 * isso depois.
 */
final class MatchedTransactionOutcomes
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
    ) {}

    /**
     * `external_id` já existente, pending virando posted: status/data (e
     * valor, exceto em perna de transferência ou parcela de plano — essas
     * têm o valor travado por outras regras do domínio, a importação nunca
     * sobrescreve).
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    public function update(RowDecision $decision, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $dateChanged = $transaction->date->toDateString() !== $decision->row->date;
        $previousStatementId = $transaction->statement_id;

        $attributes = [
            'status' => TransactionStatus::Posted,
            'date' => CarbonImmutable::parse($decision->row->date),
        ];

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
    public function replaceInstallmentOutcome(RowDecision $decision, ImportBatch $batch, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $this->replaceParcel($transaction, $decision->row, $batch, $undo);
        $stats['replaced']++;
    }

    /**
     * Confirma uma parcela de plano — já existente no banco
     * (ReplaceInstallment) ou criada mais cedo neste mesmo lote (ver
     * IngestTransactions::replaceSeededParcel): fica posted com a data e o
     * valor reais, e ganha external_id/source. statement_id nunca muda
     * aqui: é a sequência do próprio plano (StatementResolver::next a
     * partir da fatura da parcela anterior) que decide a fatura de cada
     * parcela — mais confiável do que recalcular pela data que o banco
     * informou agora, que pode cair perto do fechamento de um ciclo
     * vizinho.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     */
    public function replaceParcel(Transaction $transaction, ParsedRow $row, ImportBatch $batch, array &$undo): void
    {
        $changed = UndoSnapshot::applyAndDiff($transaction, [
            'external_id' => $row->externalId,
            'source' => $batch->format->source(),
            // Normalmente Posted (replace_installment só casa linhas não
            // pendentes vindas do banco); pendente datada no futuro (ex.:
            // parcela de cartão que o banco já relata mas não lançou) vira
            // Projected, não Posted.
            'status' => $row->status(),
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
     * Quando o alvo é uma prevista de recorrência ainda não confirmada
     * (status projected, recurrence_id preenchido — ver
     * App\Domain\Recurrences\Support\RecurrenceMatcher), também grava a
     * data e o valor reais da linha (o que a prevista tinha era só uma
     * projeção), recalcula a fatura se a data mudou de ciclo, e guarda os
     * dois no undo — mantém recurrence_id/recurrence_date como estavam.
     *
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    public function adopt(RowDecision $decision, ImportBatch $batch, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $isRecurrenceOccurrence = $transaction->status === TransactionStatus::Projected && $transaction->recurrence_id !== null;
        $previousStatementId = $transaction->statement_id;

        $attributes = [
            'external_id' => $decision->row->externalId,
            'source' => $batch->format->source(),
            'status' => $decision->row->status(),
            'original_description' => $decision->row->description,
        ];

        if ($isRecurrenceOccurrence) {
            $attributes['date'] = CarbonImmutable::parse($decision->row->date);
            $attributes['amount'] = Money::cents($decision->row->amount);
        }

        $changed = UndoSnapshot::applyAndDiff($transaction, $attributes);

        if ($isRecurrenceOccurrence) {
            $this->assignStatement->handle($transaction);

            if ($transaction->statement_id !== $previousStatementId) {
                $changed['statement_id'] = $previousStatementId;
            }
        }

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
    public function swapPending(RowDecision $decision, array &$undo, array &$stats): void
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
