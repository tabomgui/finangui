<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz um lote concluído: exclui o que ele inseriu (inclusive parcelas
 * projetadas e os planos que ficaram sem nenhuma transação, e as faturas
 * que ele criou e ficaram sem nenhuma transação), restaura as transações
 * que ele tocou (adotadas/substituídas/trocadas) ao estado salvo no undo,
 * e marca o lote como revertido. Duplicadas nunca mudaram, então não
 * precisam de restauração.
 *
 * A restauração sobrescreve o valor atual dos campos salvos no undo: se
 * alguém editou manualmente um desses campos depois da importação (ex.:
 * mudou a data de uma transação adotada), reverter apaga essa edição e
 * volta para o valor de antes da importação, não para o valor editado.
 *
 * Só o lote mais recente concluído de uma conta pode ser revertido: um
 * lote mais antigo pode ter sido a base de adoções/substituições de lotes
 * posteriores, e reverter fora de ordem bagunçaria esse histórico.
 */
final class RevertImportBatch
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
    ) {}

    /**
     * @throws ImportBatchNotRevertible
     */
    public function handle(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            // Mesma trava de IngestTransactions: a conta primeiro, depois o
            // próprio lote relido sob trava.
            Account::query()->whereKey($batch->account_id)->lockForUpdate()->firstOrFail();

            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ImportBatchStatus::Completed) {
                throw new ImportBatchNotRevertible;
            }

            $mostRecentId = ImportBatch::query()
                ->where('account_id', $locked->account_id)
                ->where('status', ImportBatchStatus::Completed)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->value('id');

            if ($mostRecentId !== $locked->id) {
                throw new ImportBatchNotRevertible('Reverta antes as importações mais recentes desta conta.');
            }

            Transaction::query()->where('import_batch_id', $locked->id)->delete();

            InstallmentPlan::query()
                ->where('import_batch_id', $locked->id)
                ->whereDoesntHave('transactions')
                ->delete();

            /** @var list<array{transaction_id: int, attributes: array<string, mixed>}> $undo */
            $undo = $locked->undo ?? [];

            foreach ($undo as $item) {
                $transaction = Transaction::query()->find($item['transaction_id']);

                if ($transaction === null) {
                    // Já não existe (ex.: excluída manualmente depois da importação): nada a restaurar.
                    continue;
                }

                $attributes = $item['attributes'];
                $hasStatement = array_key_exists('statement_id', $attributes);
                $savedStatementId = $attributes['statement_id'] ?? null;
                unset($attributes['statement_id']);

                foreach ($attributes as $key => $value) {
                    $transaction->{$key} = $value;
                }

                if ($hasStatement) {
                    if ($savedStatementId !== null && CardStatement::query()->whereKey($savedStatementId)->exists()) {
                        $transaction->statement_id = $savedStatementId;
                    } else {
                        // A fatura salva no undo não existe mais (ex.: prunada
                        // por ter ficado vazia depois da importação): a data
                        // já foi restaurada acima, então recalcula uma fatura
                        // válida em vez de gravar uma FK inexistente.
                        $this->assignStatement->handle($transaction);
                    }
                }

                $transaction->save();
            }

            /** @var list<int> $createdStatementIds */
            $createdStatementIds = $locked->created_statement_ids ?? [];

            if ($createdStatementIds !== []) {
                // "Qualquer data" (não só futuras, diferente de
                // CardStatement::pruneEmptyFuture): são faturas que este
                // lote criou, então reverter o lote deve desfazê-las por
                // completo se ninguém mais as usa.
                CardStatement::query()
                    ->whereIn('id', $createdStatementIds)
                    ->whereDoesntHave('transactions')
                    ->delete();
            }

            $locked->status = ImportBatchStatus::Reverted;
            $locked->reverted_at = CarbonImmutable::now();
            $locked->save();
        });
    }
}
