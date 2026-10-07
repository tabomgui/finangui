<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportFormat;
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
     * `external_id` já existente — dois casos, nunca os dois juntos (ver
     * IngestionPlanner::decisionForExisting()):
     *
     * 1. Pending/projetada virando posted (qualquer fonte, inclusive
     *    CSV/OFX, como antes desta sincronização bancária existir): `status`,
     *    `date` e, fora de uma perna de transferência ou de uma parcela de
     *    plano (valor travado por outras regras do domínio), também
     *    `amount` — o banco pode relatar a pendente com um valor/data mais
     *    exatos do que a projeção original. Uma mudança de data passa por
     *    App\Domain\Cards\Actions\AssignStatement, igual a uma edição manual.
     * 2. Só na sincronização bancária (`$format = Pluggy`), uma transação já
     *    fechada (posted) que o banco está relatando diferente:
     *    - `original_description` sempre acompanha o texto do banco;
     *    - `description` (o rótulo exibido) só acompanha quando ainda não
     *      foi editada manualmente (`! description_locked`) E ainda é
     *      literalmente o texto original do banco (`description` igual ao
     *      `original_description` ANTES desta chamada) — uma renomeação por
     *      regra ou pelo usuário, mesmo sem description_locked, já conta
     *      como "não é mais o texto do banco" e nunca mais é tocada aqui;
     *    - a fatura, só numa compra comum de cartão
     *      (Transaction::isPlainCardPurchase() — nunca transferência,
     *      parcela, pagamento de fatura ou fatura já escolhida à mão pelo
     *      usuário), quando `meta.bill_id` resolve para uma fatura local
     *      diferente (`$billStatementMemo`) — sempre via
     *      App\Domain\Cards\Actions\AssignStatement. Pagamento de fatura
     *      nunca muda de fatura por aqui: isso é trabalho de
     *      App\Domain\Banking\Actions\ReconcileCardPayments, que roda depois
     *      do ingest com os critérios próprios dela (payments[] da fatura,
     *      não o bill_id do crédito).
     *
     * Uma transação já `posted` nunca tem `date`/`amount` sobrescritos aqui
     * (fora da transição 1, que por definição parte de uma pendente/projetada):
     * são a identidade financeira do lançamento (conciliação, relatórios,
     * parcelas) — o próprio `external_id` já garante que é a MESMA transação
     * que o banco relatou antes; se o banco "corrigir" depois um valor ou
     * uma data que o usuário já viu/conciliou, automatizar essa troca é
     * arriscado demais para o ganho — fica para o usuário editar à mão.
     * Categoria também nunca é tocada aqui, por nenhum caminho.
     *
     * Sem nenhuma mudança de verdade (a decisão foi tomada numa leitura mais
     * antiga que o próprio `bankDataChanged()`), conta como duplicata em vez
     * de gravar um "update" vazio.
     *
     * @param  array<string, int>  $billStatementMemo  external_id da fatura do banco → id do CardStatement (ver App\Domain\Imports\Support\ImportPreloads::billStatements())
     * @param  list<array{transaction_id: int, attributes: array<string, mixed>}>  $undo
     * @param  array<string, int>  $stats
     */
    public function update(RowDecision $decision, ImportFormat $format, array $billStatementMemo, array &$undo, array &$stats): void
    {
        $transaction = $decision->transaction ?? Transaction::query()->findOrFail($decision->transactionId);
        $previousStatementId = $transaction->statement_id;
        $isBankSync = $format === ImportFormat::Pluggy;
        $oldOriginalDescription = $transaction->original_description;

        $wasOpen = $this->isStillOpen($transaction);
        $transitioningToPosted = $wasOpen && ! $decision->row->pending;
        $attributes = [];
        $dateChanged = false;

        if ($transitioningToPosted) {
            $dateChanged = $transaction->date->toDateString() !== $decision->row->date;
            $attributes['status'] = $decision->row->status();
            $attributes['date'] = CarbonImmutable::parse($decision->row->date);

            if ($transaction->transfer_id === null && $transaction->installment_plan_id === null) {
                $attributes['amount'] = Money::cents($decision->row->amount);
            }
        }

        if ($isBankSync) {
            $attributes['original_description'] = $decision->row->description;

            if (! $transaction->description_locked && $transaction->description === $oldOriginalDescription) {
                $attributes['description'] = $decision->row->description;
            }
        }

        $changed = UndoSnapshot::applyAndDiff($transaction, $attributes);

        if ($dateChanged) {
            $this->assignStatement->handle($transaction);
        }

        if ($isBankSync && $transaction->isPlainCardPurchase()) {
            $billId = $decision->row->meta['bill_id'] ?? null;
            $statementId = is_string($billId) ? ($billStatementMemo[$billId] ?? null) : null;

            if ($statementId !== null) {
                $this->assignStatement->handle($transaction, $statementId);
            }
        }

        if ($transaction->statement_id !== $previousStatementId) {
            $changed['statement_id'] = $previousStatementId;
        }

        if ($changed === []) {
            $stats['duplicates']++;

            return;
        }

        $undo[] = ['transaction_id' => $transaction->id, 'attributes' => $changed];

        $transaction->save();
        $stats['updated']++;
    }

    /**
     * Mesma regra de App\Domain\Imports\Support\IngestionPlanner::isStillOpen():
     * pendente (de qualquer fonte) ou projetada por data futura que não é
     * parcela — duplicado aqui (não exposto por IngestionPlanner) porque
     * update() decide por conta própria se o status desta transação pode
     * virar posted, sem depender de qual branch do planner escolheu Update.
     */
    private function isStillOpen(Transaction $t): bool
    {
        return $t->status === TransactionStatus::Pending
            || ($t->status === TransactionStatus::Projected && $t->installment_plan_id === null);
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
