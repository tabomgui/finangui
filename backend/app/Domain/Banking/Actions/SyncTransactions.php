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

/**
 * Terceiro passo do sync periódico de uma conta (App\Domain\Banking\Jobs\SyncConnection):
 * mapeia as transações do provedor para ParsedRow (TransactionMapper), cria
 * um ImportBatch (`format = pluggy`) e reaproveita IngestTransactions — mesmo
 * dedup, adoção de lançamento manual, parcelas e categorização (regras →
 * histórico → categoria do provedor) de um arquivo importado. Sem nenhuma
 * transação nova, nenhum lote é criado (lote vazio não ajuda ninguém).
 *
 * Depois, remove pendentes antigos (regra 6 da sincronização): uma
 * transação `pending` com `source = pluggy` desta conta, com data anterior
 * a `$syncStartedAt − 10 dias`, que não veio nesta chamada ao provedor, foi
 * cancelada pelo banco. Parcelas projetadas (`source = installment`) nunca
 * entram nessa limpeza — o filtro por `source = pluggy` já garante isso.
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
     */
    public function handle(Account $account, iterable $transactions, CarbonImmutable $syncStartedAt): void
    {
        $categoriesById = $this->categoriesById();
        $creditCard = $account->isCreditCard();
        // Conta manual vinculada depois: nada antes disso entra — já está
        // no saldo inicial que o usuário lançou à mão (ver
        // App\Domain\Banking\Support\AccountMapper::linkExisting()). Conta
        // criada pelo vínculo nunca tem esse piso.
        $minDate = $account->provider_sync_from?->toDateString();

        $rows = [];
        $receivedExternalIds = [];
        $line = 1;

        foreach ($transactions as $transaction) {
            $receivedExternalIds[] = $transaction->id;

            if ($minDate !== null && $transaction->date < $minDate) {
                continue;
            }

            $rows[] = TransactionMapper::toParsedRow($transaction, $creditCard, $line++, $categoriesById);
        }

        if ($rows !== []) {
            $this->ingestRows($account, $rows, $syncStartedAt);
        }

        $this->deleteStalePending($account, $receivedExternalIds, $syncStartedAt);
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

        $this->ingest->handle($batch, $rows);
    }

    /**
     * @param  list<string>  $receivedExternalIds
     */
    private function deleteStalePending(Account $account, array $receivedExternalIds, CarbonImmutable $syncStartedAt): void
    {
        $threshold = $syncStartedAt->subDays(self::STALE_PENDING_DAYS)->toDateString();

        Transaction::query()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::Pluggy->value)
            ->where('status', TransactionStatus::Pending->value)
            ->where('date', '<', $threshold)
            ->when($receivedExternalIds !== [], fn ($query) => $query->whereNotIn('external_id', $receivedExternalIds))
            ->delete();
    }

    /**
     * @return array<string, ProviderCategory>
     */
    private function categoriesById(): array
    {
        $byId = [];

        foreach ($this->provider->categories() as $category) {
            $byId[$category->id] = $category;
        }

        return $byId;
    }
}
