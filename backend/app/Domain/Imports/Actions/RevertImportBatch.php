<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\UnlinkTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 *
 * Transação inserida pelo lote que foi ligada como perna de transferência
 * (automaticamente por App\Domain\Transfers\Actions\DetectTransfers) é
 * desligada antes de excluída: a outra perna é de outra conta (nunca deste
 * mesmo lote) e volta a ser um lançamento comum em vez de ficar apontando
 * para um par que não existe mais.
 */
final class RevertImportBatch
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly UnlinkTransfer $unlinkTransfer,
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

            // format pluggy nunca é revertível pela UI: a sincronização
            // bancária não é um lote que o usuário mandou e possa desfazer
            // à mão (ver App\Domain\Banking\Actions\SyncTransactions).
            if ($locked->status !== ImportBatchStatus::Completed || $locked->format === ImportFormat::Pluggy) {
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

            // Uma perna de transferência ligada automaticamente por este
            // lote: a outra perna pertence a outra conta, por definição
            // (ver App\Domain\Transfers\Support\TransferMatcher), então
            // nunca está entre as transações deste lote — desliga antes de
            // apagar, para ela voltar a ser um lançamento comum em vez de
            // ficar com transfer_id apontando para um par que não existe
            // mais. Sem remember: não houve decisão do usuário sobre o
            // par, então uma futura detecção pode religá-lo normalmente.
            $linkedTransferIds = Transaction::query()
                ->where('import_batch_id', $locked->id)
                ->whereNotNull('transfer_id')
                ->pluck('transfer_id');

            foreach ($linkedTransferIds as $transferId) {
                try {
                    $this->unlinkTransfer->handle($transferId, remember: false);
                } catch (ModelNotFoundException) {
                    // transfer_id órfão: a outra perna já não existe (nunca
                    // deveria acontecer, mas UnlinkTransfer exige as duas
                    // pernas presentes) — zera o campo desta mesma, em vez
                    // de deixar a exceção abortar o revert inteiro; ela é
                    // excluída já a seguir, de qualquer forma.
                    Transaction::query()
                        ->where('import_batch_id', $locked->id)
                        ->where('transfer_id', $transferId)
                        ->update(['transfer_id' => null]);
                }
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

                if ($transaction->transfer_id !== null) {
                    // Esta é a entrada de undo gravada por DetectTransfers::applyLinks() para a
                    // perna externa (de outra conta) que este lote ligou automaticamente — guarda
                    // a categoria/fatura de antes da ligação. Se, desde então, a perna voltou a
                    // ligar (outra detecção, ou "juntar à mão"), ela já é perna de um par atual:
                    // aplicar o undo agora gravaria categoria/fatura numa perna de transferência,
                    // violando o invariante (nunca tem categoria). Deixa como está.
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

                if ($transaction->status === TransactionStatus::Projected
                    && $transaction->source === TransactionSource::Recurrence
                    && $transaction->recurrence_id === null) {
                    // A recorrência que a adotou foi excluída depois da
                    // importação (DeleteRecurrence zera recurrence_id em vez
                    // de excluir uma ocorrência que já virou lançamento de
                    // verdade — ela não sabe que o revert ainda vai desfazer
                    // isso). Restaurar deixaria uma prevista órfã, sem
                    // recorrência nenhuma para confirmá-la ou regenerá-la:
                    // exclui em vez de salvar.
                    $transaction->delete();

                    continue;
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
