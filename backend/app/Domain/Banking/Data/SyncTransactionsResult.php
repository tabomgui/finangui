<?php

namespace App\Domain\Banking\Data;

/**
 * O que App\Domain\Banking\Actions\SyncTransactions de fato gravou numa
 * conta, para App\Domain\Banking\Jobs\SyncConnection alimentar o histórico
 * de sincronização (App\Domain\Banking\Models\BankSyncRun): os ids
 * inseridos (linha nova de verdade, `import_batch_id` do lote — ver
 * SyncTransactions::ingestRows()) e quantas transações já existentes foram
 * atualizadas (status/valor/data/fatura/descrição — ver
 * App\Domain\Imports\Actions\MatchedTransactionOutcomes::update()).
 */
final readonly class SyncTransactionsResult
{
    /**
     * @param  list<int>  $insertedIds
     */
    public function __construct(
        public array $insertedIds = [],
        public int $updatedCount = 0,
    ) {}

    public function merge(self $other): self
    {
        return new self([...$this->insertedIds, ...$other->insertedIds], $this->updatedCount + $other->updatedCount);
    }
}
