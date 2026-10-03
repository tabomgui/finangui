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
use Carbon\CarbonImmutable;
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
 * em lote uma parcela (`installment_plan_id`) ou perna de transferência
 * (`transfer_id`): mesmo pendente e antiga, fica de fora da exclusão — quem
 * decide o destino dessas é App\Domain\Transactions\Actions\DeleteTransaction,
 * não uma limpeza automática.
 */
final class SyncTransactions
{
    private const STALE_PENDING_DAYS = 10;

    public function __construct(
        private readonly BankProvider $provider,
        private readonly IngestTransactions $ingest,
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

        $oldestStaleDate = Transaction::query()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::Pluggy->value)
            ->where('status', TransactionStatus::Pending->value)
            ->where('date', '<', $threshold)
            ->min('date');

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
            'rows' => array_map(fn (ParsedRow $row) => $row->toArray(), $rows),
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
     * @param  list<string>  $receivedIds  ids da listagem completa (ver cleanupStalePending()) — o que não está aqui o banco não reportou mais
     */
    private function deleteStalePending(Account $account, array $receivedIds, string $threshold): void
    {
        Transaction::query()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::Pluggy->value)
            ->where('status', TransactionStatus::Pending->value)
            ->where('date', '<', $threshold)
            // Parcela e perna de transferência nunca somem num delete em
            // lote: quem decide o destino delas é
            // App\Domain\Transactions\Actions\DeleteTransaction.
            ->whereNull('installment_plan_id')
            ->whereNull('transfer_id')
            ->whereRaw('external_id <> ALL(?::text[])', [self::pgTextArray($receivedIds)])
            ->delete();
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
