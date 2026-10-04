<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Support\TransactionMapper;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\DetectTransfers;
use App\Domain\Transfers\Actions\UnlinkTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Terceiro passo do sync periódico de uma conta (App\Domain\Banking\Jobs\SyncConnection):
 * mapeia as transações do provedor para ParsedRow (TransactionMapper), cria
 * um ImportBatch (`format = pluggy`) e reaproveita IngestTransactions — mesmo
 * dedup, adoção de lançamento manual, parcelas e categorização (regras →
 * histórico → categoria do provedor) de um arquivo importado. Sem nenhuma
 * transação nova, nenhum lote é criado (lote vazio não ajuda ninguém); um
 * lote cujas linhas todas viraram `duplicate` (nada de fato mudou) também é
 * descartado — e se o próprio ingest falhar, o lote recém-criado é excluído
 * (nunca fica um lote `pending` órfão, que IngestTransactions nunca chegou a
 * fechar).
 *
 * Depois, remove pendentes antigos (regra 6 da sincronização) — mas só com
 * base numa listagem completa: se a conta tem pendentes `source = pluggy`
 * com data anterior a `$syncStartedAt − 10 dias`, busca de novo com
 * `dateFrom` = a data do mais antigo deles (sem createdAtFrom, para pegar o
 * período inteiro) e ingere essas linhas também (podem trazer uma mudança de
 * status do próprio banco para o mesmo id); só os ids dessa segunda busca
 * contam como "veio no sync" para decidir o que excluir. Sem conseguir essa
 * listagem (o provedor falhou), a limpeza é pulada por completo — melhor não
 * excluir nada do que excluir com base numa janela incompleta. Nunca exclui
 * em lote uma parcela (`installment_plan_id`), mesmo pendente e antiga, que
 * fica de fora da exclusão — quem decide o destino dela é
 * App\Domain\Transactions\Actions\DeleteTransaction, não uma limpeza
 * automática. Uma perna de transferência (`transfer_id`) pendente antiga e
 * ausente pode ser excluída normalmente, mas é desligada primeiro
 * (App\Domain\Transfers\Actions\UnlinkTransfer, sem lembrar o par como
 * descartado): a outra perna é sempre de outra conta — nunca desta mesma
 * limpeza, escopada por conta — e volta a ser um lançamento comum em vez de
 * ficar apontando para um par que não existe mais.
 */
final class SyncTransactions
{
    private const STALE_PENDING_DAYS = 10;

    public function __construct(
        private readonly BankProvider $provider,
        private readonly IngestTransactions $ingest,
        private readonly UnlinkTransfer $unlinkTransfer,
        private readonly DetectTransfers $detectTransfers,
    ) {}

    /**
     * @param  iterable<ProviderTransaction>  $transactions
     * @param  array<string, ProviderCategory>  $categoriesById  categorias do provedor (ver App\Domain\Banking\Jobs\SyncConnection, que busca uma vez por job e repassa para cada conta)
     */
    public function handle(Account $account, iterable $transactions, CarbonImmutable $syncStartedAt, array $categoriesById): void
    {
        $creditCard = $account->isCreditCard();
        $minDate = $account->provider_sync_from?->toDateString();

        [$rows] = $this->mapRows($transactions, $creditCard, $minDate, $categoriesById);

        if ($rows !== []) {
            $this->ingestRows($account, $rows, $syncStartedAt);
        }

        $this->cleanupStalePending($account, $creditCard, $minDate, $categoriesById, $syncStartedAt);
    }

    /**
     * @param  iterable<ProviderTransaction>  $transactions
     * @param  array<string, ProviderCategory>  $categoriesById
     * @return array{0: list<ParsedRow>, 1: list<string>} linhas mapeadas (já sem as anteriores a $minDate) e os ids de tudo que o provedor devolveu (inclusive o que ficou de fora por $minDate — "veio no sync" conta esses também)
     */
    private function mapRows(iterable $transactions, bool $creditCard, ?string $minDate, array $categoriesById): array
    {
        $rows = [];
        $receivedIds = [];
        $line = 1;

        foreach ($transactions as $transaction) {
            $receivedIds[] = $transaction->id;

            if ($minDate !== null && $transaction->date < $minDate) {
                continue;
            }

            $rows[] = TransactionMapper::toParsedRow($transaction, $creditCard, $line++, $categoriesById);
        }

        return [$rows, $receivedIds];
    }

    /**
     * Conta manual vinculada depois: nada antes de provider_sync_from entra
     * — já está no saldo inicial que o usuário lançou à mão (ver
     * App\Domain\Banking\Support\AccountMapper::linkExisting()). Conta
     * criada pelo vínculo nunca tem esse piso (Σ $minDate null).
     *
     * @param  array<string, ProviderCategory>  $categoriesById
     */
    private function cleanupStalePending(Account $account, bool $creditCard, ?string $minDate, array $categoriesById, CarbonImmutable $syncStartedAt): void
    {
        $threshold = $syncStartedAt->subDays(self::STALE_PENDING_DAYS)->toDateString();

        $oldestStaleDate = $this->stalePendingQuery($account, $threshold)->min('date');

        if ($oldestStaleDate === null) {
            return;
        }

        try {
            $fullListing = $this->provider->transactions(
                $account->external_id,
                $creditCard,
                CarbonImmutable::parse($oldestStaleDate),
                null,
            );

            [$rows, $receivedIds] = $this->mapRows($fullListing, $creditCard, $minDate, $categoriesById);
        } catch (Throwable $e) {
            Log::warning('Pluggy: falha ao buscar a listagem completa para a limpeza de pendentes antigos; pulando a limpeza deste sync.', [
                'account_id' => $account->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($rows !== []) {
            $this->ingestRows($account, $rows, $syncStartedAt);
        }

        $this->deleteStalePending($account, $receivedIds, $threshold);
    }

    /**
     * @param  list<ParsedRow>  $rows
     */
    private function ingestRows(Account $account, array $rows, CarbonImmutable $syncStartedAt): void
    {
        $batch = ImportBatch::create([
            'account_id' => $account->id,
            'format' => ImportFormat::Pluggy,
            'source' => ImportFormat::Pluggy->source()->value,
            'filename' => 'Sincronização '.$syncStartedAt->toDateString(),
            'status' => ImportBatchStatus::Pending,
            // null, não as linhas: IngestTransactions::handle() recebe $rows
            // por parâmetro (não relê a coluna) e um lote pluggy nunca passa
            // por ConfirmImportBatch (o único caminho que releria isso) —
            // só o stats final importa para este formato.
            'rows' => null,
            'stats' => [],
        ]);

        try {
            $completed = $this->ingest->handle($batch, $rows);
        } catch (Throwable $e) {
            // IngestTransactions::handle() roda numa transação própria —
            // se ela falhar e desfizer tudo, o ImportBatch::create() acima
            // já tinha sido confirmado antes disso (commit separado): sem
            // isso, um lote `pending` ficaria órfão para sempre.
            ImportBatch::query()->whereKey($batch->id)->delete();

            throw $e;
        }

        if ($this->isNoOp($completed)) {
            // Nada mudou de fato (tudo já estava importado) — um lote
            // "concluído" sem nenhum efeito só ocuparia espaço na lista de
            // sincronizações, sempre marcado como não revertível mesmo
            // assim.
            ImportBatch::query()->whereKey($completed->id)->delete();
        }
    }

    private function isNoOp(ImportBatch $batch): bool
    {
        $stats = $batch->stats ?? [];

        foreach (['inserted', 'updated', 'replaced', 'adopted', 'swapped'] as $key) {
            if ((int) ($stats[$key] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Desliga (sem lembrar como descartado) qualquer perna de transferência
     * entre as que serão excluídas antes do delete em lote: a outra perna,
     * de outra conta, sobrevive como lançamento comum em vez de ficar com
     * um transfer_id para um par que não existe mais. Rodar DetectTransfers
     * para quem sobreviveu, no fim: voltando a ser uma candidata comum,
     * pode formar um par novo com outra transação (ligar de verdade ou
     * virar sugestão) — sem isso, ficaria esperando o próximo lançamento
     * novo em qualquer conta para ser considerada de novo.
     *
     * @param  list<string>  $receivedIds  ids da listagem completa (ver cleanupStalePending()) — o que não está aqui o banco não reportou mais
     */
    private function deleteStalePending(Account $account, array $receivedIds, string $threshold): void
    {
        $query = fn () => $this->stalePendingQuery($account, $threshold)
            ->whereRaw('external_id <> ALL(?::text[])', [self::pgTextArray($receivedIds)])
            // Uma perna de transferência só entra nesta limpeza quando foi de fato inserida por
            // este sync (import_batch_id preenchido, ver IngestTransactions::insertNew()). Uma
            // perna criada pelo usuário e só adotada depois (ex.: a perna de PayStatement no
            // cartão casando com a linha do banco) nunca passa por aqui, mesmo com source já
            // virado pluggy e status pending/projected (ver MatchedTransactionOutcomes::adopt(),
            // que nunca grava import_batch_id) — ela não é "nossa" para apagar como lixo de sync.
            // Transações sem transfer_id (a maioria) não são afetadas por esta condição.
            ->where(fn (Builder $q) => $q->whereNull('transfer_id')->orWhereNotNull('import_batch_id'));

        $transferIds = $query()->whereNotNull('transfer_id')->pluck('transfer_id');

        $survivorIds = [];
        foreach ($transferIds as $transferId) {
            // A outra perna é sempre de outra conta — nunca desta, a única
            // escopada pela limpeza (ver stalePendingQuery()): acha o id
            // dela antes de desligar.
            $otherLeg = Transaction::query()
                ->where('transfer_id', $transferId)
                ->where('account_id', '!=', $account->id)
                ->first();

            $this->unlinkTransfer->handle($transferId, remember: false);

            if ($otherLeg !== null) {
                $survivorIds[] = $otherLeg->id;
            }
        }

        $query()->delete();

        if ($survivorIds !== []) {
            $this->detectTransfers->handle($survivorIds);
        }
    }

    /**
     * Pendente (de qualquer status "ainda não resolvido") antiga, candidata
     * à limpeza da regra 6 — a mesma base para decidir a data mais antiga
     * (min('date')) e para o delete em si, senão as duas poderiam enxergar
     * conjuntos diferentes. `status` inclui Projected (não só Pending): uma
     * pendente futura que nunca chegou a lançar (ver
     * App\Domain\Imports\Data\ParsedRow::status()) também pode ter sido
     * cancelada pelo banco. Parcela (`installment_plan_id`) nunca entra
     * aqui, mesmo antiga e pendente: quem decide o destino dela é
     * App\Domain\Transactions\Actions\DeleteTransaction, não uma limpeza
     * automática. Perna de transferência (`transfer_id`) entra normalmente
     * — ver deleteStalePending(), que a desliga antes de excluir.
     *
     * @return Builder<Transaction>
     */
    private function stalePendingQuery(Account $account, string $threshold): Builder
    {
        return Transaction::query()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::Pluggy->value)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Projected->value])
            ->where('date', '<', $threshold)
            ->whereNull('installment_plan_id');
    }

    /**
     * Literal de array do Postgres para o `<> ALL(...)` acima — uma única
     * ligação de parâmetro em vez de um `NOT IN (?, ?, ?, ...)` com uma
     * posição por id (a lista pode ser grande depois de muitos sincs).
     *
     * @param  list<string>  $values
     */
    private static function pgTextArray(array $values): string
    {
        $escaped = array_map(
            fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"',
            $values,
        );

        return '{'.implode(',', $escaped).'}';
    }
}
